<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\GroupConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;
use Cbox\LaravelQueueAutoscale\Events\SlaBreached;
use Cbox\LaravelQueueAutoscale\Events\SlaRecovered;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Output\DataTransferObjects\QueueStats;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingEngine;
use Cbox\LaravelQueueAutoscale\Scaling\SlaSignalSource;
use Cbox\LaravelQueueAutoscale\Testing\InMemoryPickupTimeStore;
use Cbox\LaravelQueueAutoscale\Testing\InteractsWithAutoscaling;
use Cbox\LaravelQueueAutoscale\Testing\QueueMetricsFactory;
use Cbox\LaravelQueueAutoscale\Workers\WorkerProcess;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Process\Process;

/**
 * SLA breach is judged on the signal the strategy scaled on.
 *
 * It used to be judged on the oldest job's age alone, while HybridStrategy
 * scaled on the p95 pickup time. Amazon SQS cannot report the oldest job's age
 * — it is always 0 — so on SQS a queue could never enter breach: SlaBreached
 * and SlaRecovered never fired and the console said 'ok', while the strategy's
 * own p95 showed the SLA blown wide open.
 *
 * These drive the manager's real per-queue, group and pinned paths, with the
 * pickup window supplied by the package's own in-memory store. Workers are
 * capped at zero so nothing is spawned: breach state is the subject here, and
 * it does not depend on the target.
 */
uses(InteractsWithAutoscaling::class);

/**
 * @return array<string, mixed>
 */
function breachQueueRules(): array
{
    return [
        'sla' => ['target_seconds' => 30, 'percentile' => 95, 'window_seconds' => 300, 'min_samples' => 5],
        'workers' => ['min' => 0, 'max' => 0],
    ];
}

function evaluateQueueForBreach(QueueMetricsData $metrics): AutoscaleManager
{
    $manager = app(AutoscaleManager::class);

    (new ReflectionMethod($manager, 'evaluateQueue'))
        ->invoke($manager, $metrics->connection, $metrics->queue, $metrics);

    return $manager;
}

function breachQueueStats(AutoscaleManager $manager, string $key): QueueStats
{
    /** @var array<string, QueueStats> $stats */
    $stats = (new ReflectionProperty($manager, 'currentQueueStats'))->getValue($manager);

    return $stats[$key];
}

beforeEach(function (): void {
    config()->set('queue-autoscale.policies', []);
    config()->set('queue-autoscale.groups', []);
    config()->set('queue-autoscale.excluded', []);
    config()->set('queue-autoscale.queues', ['orders' => breachQueueRules()]);

    Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00'));

    $this->pickups = $this->fakePickupTimes();

    Event::fake([SlaBreached::class, SlaRecovered::class]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('an SQS queue whose p95 pickup is over the target enters breach', function (): void {
    /** @var InMemoryPickupTimeStore $pickups */
    $pickups = $this->pickups;
    $pickups->seedRepeated(20, 45.0, 'sqs', 'orders');

    $manager = evaluateQueueForBreach(QueueMetricsFactory::onSqs(200, 'orders'));

    Event::assertDispatchedTimes(SlaBreached::class, 1);
    Event::assertDispatched(SlaBreached::class, function (SlaBreached $event): bool {
        return $event->connection === 'sqs'
            && $event->queue === 'orders'
            && $event->oldestJobAge === 0
            && $event->slaTarget === 30
            && $event->pending === 200
            && $event->slaSignal?->source === SlaSignalSource::PickupPercentile
            && $event->slaSignal->percentile === 95
            && $event->slaSignal->seconds === 45.0
            && $event->breachSeconds() === 15;
    });
    expect(breachQueueStats($manager, 'sqs:orders')->slaStatus)->toBe('breached');
});

test('an SQS breach fires once and stays open while p95 stays over the target', function (): void {
    $this->pickups->seedRepeated(20, 45.0, 'sqs', 'orders');

    evaluateQueueForBreach(QueueMetricsFactory::onSqs(200, 'orders'));
    Carbon::setTestNow(now()->addSeconds(5));
    evaluateQueueForBreach(QueueMetricsFactory::onSqs(180, 'orders'));
    Carbon::setTestNow(now()->addSeconds(5));
    $manager = evaluateQueueForBreach(QueueMetricsFactory::onSqs(150, 'orders'));

    Event::assertDispatchedTimes(SlaBreached::class, 1);
    Event::assertNotDispatched(SlaRecovered::class);
    expect(breachQueueStats($manager, 'sqs:orders')->slaStatus)->toBe('breached');
});

test('an SQS breach recovers once the p95 over the window drops below the target', function (): void {
    $this->pickups->seedRepeated(20, 45.0, 'sqs', 'orders');
    evaluateQueueForBreach(QueueMetricsFactory::onSqs(200, 'orders'));

    // Inside the window the slow pickups still dominate the percentile, even
    // with a burst of fast ones: recovery is a property of the window, not of
    // the latest pickup.
    Carbon::setTestNow(now()->addSeconds(60));
    $this->pickups->seedRepeated(10, 2.0, 'sqs', 'orders');
    evaluateQueueForBreach(QueueMetricsFactory::onSqs(40, 'orders'));

    Event::assertNotDispatched(SlaRecovered::class);

    // Once the slow pickups age out of the 300-second window, the percentile
    // is the fast ones alone.
    Carbon::setTestNow(now()->addSeconds(250));
    $this->pickups->seedRepeated(10, 2.0, 'sqs', 'orders');
    $manager = evaluateQueueForBreach(QueueMetricsFactory::onSqs(10, 'orders'));

    Event::assertDispatchedTimes(SlaRecovered::class, 1);
    Event::assertDispatched(SlaRecovered::class, function (SlaRecovered $event): bool {
        return $event->queue === 'orders'
            && $event->currentJobAge === 0
            && $event->slaSignal?->source === SlaSignalSource::PickupPercentile
            && $event->slaSignal->seconds === 2.0
            && $event->marginSeconds() === 28;
    });
    expect(breachQueueStats($manager, 'sqs:orders')->slaStatus)->toBe('ok');
});

test('a breach is not ended by the pickup window running short of samples while jobs are still waiting', function (): void {
    // The flap this guards against: a stalled SQS queue makes no pickups, so
    // its window drains below min_samples and the signal falls back to an
    // oldest age SQS reports as 0. Reading that as recovery would announce
    // SlaRecovered for a queue further behind than ever.
    $this->pickups->seedRepeated(20, 45.0, 'sqs', 'orders');
    evaluateQueueForBreach(QueueMetricsFactory::onSqs(200, 'orders'));

    $this->pickups->clear('sqs', 'orders');
    $manager = evaluateQueueForBreach(QueueMetricsFactory::onSqs(240, 'orders'));

    Event::assertNotDispatched(SlaRecovered::class);
    expect(breachQueueStats($manager, 'sqs:orders')->slaStatus)->toBe('breached');

    // Slow pickups return: still the same breach, not a new one.
    $this->pickups->seedRepeated(20, 50.0, 'sqs', 'orders');
    evaluateQueueForBreach(QueueMetricsFactory::onSqs(240, 'orders'));

    Event::assertDispatchedTimes(SlaBreached::class, 1);
    Event::assertNotDispatched(SlaRecovered::class);
});

test('on Redis a short window with a fresh head of queue recovers the breach', function (): void {
    // Redis reports a real oldest age. An age of 0 with jobs pending is a head
    // job under a second old, not a missing reading, so it ends the breach.
    $this->pickups->seedRepeated(20, 45.0, 'redis', 'orders');
    evaluateQueueForBreach(QueueMetricsFactory::behind(200, oldestJobAge: 10, queue: 'orders'));

    $this->pickups->clear('redis', 'orders');
    $manager = evaluateQueueForBreach(QueueMetricsFactory::behind(3, oldestJobAge: 0, queue: 'orders'));

    Event::assertDispatchedTimes(SlaBreached::class, 1);
    Event::assertDispatchedTimes(SlaRecovered::class, 1);
    expect(breachQueueStats($manager, 'redis:orders')->slaStatus)->toBe('ok');
});

test('a driver the package cannot read an age from is held like SQS', function (): void {
    $this->pickups->seedRepeated(20, 45.0, 'beanstalk', 'orders');
    config()->set('queue-autoscale.queues', ['orders' => breachQueueRules() + ['connection' => 'beanstalk']]);
    $metrics = fn (int $pending): QueueMetricsData => QueueMetricsFactory::make([
        'connection' => 'beanstalk',
        'queue' => 'orders',
        'driver' => 'beanstalkd',
        'depth' => $pending,
        'pending' => $pending,
        'oldestJobAge' => 0,
    ]);

    evaluateQueueForBreach($metrics(200));
    $this->pickups->clear('beanstalk', 'orders');
    evaluateQueueForBreach($metrics(200));

    Event::assertDispatchedTimes(SlaBreached::class, 1);
    Event::assertNotDispatched(SlaRecovered::class);
});

test('a breach held through a short window ends once the queue is empty', function (): void {
    $this->pickups->seedRepeated(20, 45.0, 'sqs', 'orders');
    evaluateQueueForBreach(QueueMetricsFactory::onSqs(200, 'orders'));

    $this->pickups->clear('sqs', 'orders');
    evaluateQueueForBreach(QueueMetricsFactory::onSqs(0, 'orders'));

    Event::assertDispatchedTimes(SlaRecovered::class, 1);
});

test('a queue with no pickup samples is judged on the oldest job age exactly as before', function (): void {
    // The Redis case with the pickup window empty: nothing about it changed.
    $manager = evaluateQueueForBreach(QueueMetricsFactory::behind(50, oldestJobAge: 31, queue: 'orders'));

    Event::assertDispatchedTimes(SlaBreached::class, 1);
    Event::assertDispatched(SlaBreached::class, fn (SlaBreached $event): bool => $event->oldestJobAge === 31
        && $event->slaSignal?->source === SlaSignalSource::OldestJobAgeFallback
        && $event->breachSeconds() === 1);
    expect(breachQueueStats($manager, 'redis:orders')->slaStatus)->toBe('breached');

    $manager = evaluateQueueForBreach(QueueMetricsFactory::behind(5, oldestJobAge: 10, queue: 'orders'));

    Event::assertDispatchedTimes(SlaRecovered::class, 1);
    expect(breachQueueStats($manager, 'redis:orders')->slaStatus)->toBe('ok');
});

test('an oldest job age of zero on an empty queue is never a breach', function (): void {
    $manager = evaluateQueueForBreach(QueueMetricsFactory::idle('orders'));

    Event::assertNotDispatched(SlaBreached::class);
    expect(breachQueueStats($manager, 'redis:orders')->slaStatus)->toBe('ok');
});

test('a stalled queue breaches on its head job while the window still holds fast pickups', function (): void {
    // Workers hang: no new pickups are recorded, so the window keeps the fast
    // ones from before the stall and p95 stays low. The job at the head of the
    // queue is still waiting, and its age is a pickup that will be slower than
    // the target when it is finally recorded.
    $this->pickups->seedRepeated(20, 1.0, 'redis', 'orders');
    $manager = evaluateQueueForBreach(QueueMetricsFactory::behind(500, oldestJobAge: 200, queue: 'orders'));

    Event::assertDispatchedTimes(SlaBreached::class, 1);
    Event::assertDispatched(SlaBreached::class, function (SlaBreached $event): bool {
        return $event->oldestJobAge === 200
            && $event->slaSignal?->source === SlaSignalSource::OldestJobAge
            && $event->slaSignal->seconds === 200.0
            && $event->signalSeconds() === 200.0;
    });
    expect(breachQueueStats($manager, 'redis:orders')->slaStatus)->toBe('breached');
});

test('a head job just under the target does not breach a queue whose p95 is fast', function (): void {
    $this->pickups->seedRepeated(20, 1.0, 'redis', 'orders');
    $manager = evaluateQueueForBreach(QueueMetricsFactory::behind(10, oldestJobAge: 29, queue: 'orders'));

    Event::assertNotDispatched(SlaBreached::class);
    expect(breachQueueStats($manager, 'redis:orders')->slaStatus)->toBe('warning');
});

test('a young head of queue does not hide a p95 that has blown through the target', function (): void {
    $this->pickups->seedRepeated(20, 60.0, 'redis', 'orders');
    $manager = evaluateQueueForBreach(QueueMetricsFactory::behind(10, oldestJobAge: 2, queue: 'orders'));

    Event::assertDispatchedTimes(SlaBreached::class, 1);
    Event::assertDispatched(SlaBreached::class, fn (SlaBreached $event): bool => $event->slaSignal?->source === SlaSignalSource::PickupPercentile);
    expect(breachQueueStats($manager, 'redis:orders')->slaStatus)->toBe('breached');
});

test('a breach recovers only once both the percentile and the head job are under the target', function (): void {
    $this->pickups->seedRepeated(20, 60.0, 'redis', 'orders');
    evaluateQueueForBreach(QueueMetricsFactory::behind(100, oldestJobAge: 70, queue: 'orders'));

    // The slow pickups age out of the window, but the head job is still over.
    Carbon::setTestNow(now()->addSeconds(301));
    $this->pickups->seedRepeated(20, 2.0, 'redis', 'orders');
    evaluateQueueForBreach(QueueMetricsFactory::behind(40, oldestJobAge: 45, queue: 'orders'));

    Event::assertNotDispatched(SlaRecovered::class);

    Carbon::setTestNow(now()->addSeconds(5));
    $manager = evaluateQueueForBreach(QueueMetricsFactory::behind(5, oldestJobAge: 3, queue: 'orders'));

    Event::assertDispatchedTimes(SlaBreached::class, 1);
    Event::assertDispatchedTimes(SlaRecovered::class, 1);
    expect(breachQueueStats($manager, 'redis:orders')->slaStatus)->toBe('ok');
});

test('the warning band is over 80 percent of the target on the same signal', function (float $p95, string $status): void {
    $this->pickups->seedRepeated(20, $p95, 'sqs', 'orders');

    $manager = evaluateQueueForBreach(QueueMetricsFactory::onSqs(20, 'orders'));

    expect(breachQueueStats($manager, 'sqs:orders')->slaStatus)->toBe($status);
})->with([
    'well inside' => [10.0, 'ok'],
    'exactly 80%' => [24.0, 'ok'],
    'just over 80%' => [24.5, 'warning'],
    'just under target' => [29.9, 'warning'],
    'at target' => [30.0, 'breached'],
]);

test('a custom strategy that reports no signal is judged on the oldest job age, as before', function (): void {
    app()->instance(ScalingStrategyContract::class, new class implements ScalingStrategyContract
    {
        public function calculateTargetWorkers(QueueMetricsData $metrics, QueueConfiguration $config): int
        {
            return 0;
        }

        public function getLastReason(): string
        {
            return 'custom';
        }

        public function getLastPrediction(): ?float
        {
            return null;
        }
    });
    app()->forgetInstance(ScalingEngine::class);
    app()->forgetInstance(AutoscaleManager::class);

    // The pickup window says breached. This strategy never looks at it, so
    // breach does not either: the SQS gap is unchanged for it, by design.
    $this->pickups->seedRepeated(20, 45.0, 'sqs', 'orders');
    $manager = evaluateQueueForBreach(QueueMetricsFactory::onSqs(200, 'orders'));

    Event::assertNotDispatched(SlaBreached::class);
    expect(breachQueueStats($manager, 'sqs:orders')->slaStatus)->toBe('ok');

    // Oldest age still opens and closes a breach exactly as it always did,
    // including recovering on a zero reading with jobs pending.
    evaluateQueueForBreach(QueueMetricsFactory::behind(50, oldestJobAge: 40, queue: 'orders'));
    Event::assertDispatched(SlaBreached::class, fn (SlaBreached $event): bool => $event->slaSignal?->source === SlaSignalSource::OldestJobAge);

    evaluateQueueForBreach(QueueMetricsFactory::make(['queue' => 'orders', 'pending' => 50, 'oldestJobAge' => 0]));
    Event::assertDispatchedTimes(SlaRecovered::class, 1);
});

test('a group breaches on the p95 pooled across its member queues', function (): void {
    config()->set('queue-autoscale.queues', []);
    $group = GroupConfiguration::fromConfig('notifications', [
        'connection' => 'sqs',
        'queues' => ['mail', 'sms'],
        'overrides' => breachQueueRules(),
    ]);

    // Neither member alone has min_samples; pooled, they do.
    $this->pickups->seedRepeated(3, 50.0, 'sqs', 'mail');
    $this->pickups->seedRepeated(3, 50.0, 'sqs', 'sms');

    $metricsByKey = [
        'sqs:mail' => QueueMetricsFactory::onSqs(120, 'mail'),
        'sqs:sms' => QueueMetricsFactory::onSqs(80, 'sms'),
    ];

    $manager = app(AutoscaleManager::class);
    $evaluateGroup = new ReflectionMethod($manager, 'evaluateGroup');
    $evaluateGroup->invoke($manager, $group, $metricsByKey);

    Event::assertDispatchedTimes(SlaBreached::class, 1);
    Event::assertDispatched(SlaBreached::class, fn (SlaBreached $event): bool => $event->queue === 'notifications'
        && $event->oldestJobAge === 0
        && $event->pending === 200
        && $event->slaSignal?->source === SlaSignalSource::PickupPercentile);
    expect(breachQueueStats($manager, 'group:sqs:notifications')->slaStatus)->toBe('breached');

    Carbon::setTestNow(now()->addSeconds(301));
    $this->pickups->seedRepeated(5, 1.0, 'sqs', 'mail');
    $evaluateGroup->invoke($manager, $group, $metricsByKey);

    Event::assertDispatchedTimes(SlaRecovered::class, 1);
    Event::assertDispatched(SlaRecovered::class, fn (SlaRecovered $event): bool => $event->queue === 'notifications');
});

test('a pinned SQS queue breaches on its pickup percentile even though the strategy never runs for it', function (): void {
    config()->set('queue-autoscale.queues', [
        'ledger' => [
            'sla' => ['target_seconds' => 30, 'percentile' => 95, 'window_seconds' => 300, 'min_samples' => 5],
            'workers' => ['min' => 1, 'max' => 1, 'scalable' => false],
        ],
    ]);

    // One live worker already present, so supervision has nothing to spawn.
    $manager = app(AutoscaleManager::class);
    $pool = (new ReflectionProperty($manager, 'pool'))->getValue($manager);
    $pool->add(new class(new Process(['true']), 'sqs', 'ledger', now()) extends WorkerProcess
    {
        public function isRunning(): bool
        {
            return true;
        }

        public function isTerminating(): bool
        {
            return false;
        }
    });

    $this->pickups->seedRepeated(10, 90.0, 'sqs', 'ledger');
    $config = QueueConfiguration::fromConfig('sqs', 'ledger');

    (new ReflectionMethod($manager, 'superviseQueue'))
        ->invoke($manager, $config, QueueMetricsFactory::onSqs(30, 'ledger'), null);

    Event::assertDispatchedTimes(SlaBreached::class, 1);
    Event::assertDispatched(SlaBreached::class, fn (SlaBreached $event): bool => $event->queue === 'ledger'
        && $event->slaSignal?->seconds === 90.0
        && $event->slaSignal->source === SlaSignalSource::PickupPercentile);
    expect(breachQueueStats($manager, 'sqs:ledger')->slaStatus)->toBe('breached');
});

function pinnedLedgerManager(): AutoscaleManager
{
    config()->set('queue-autoscale.queues', [
        'ledger' => [
            'sla' => ['target_seconds' => 30, 'percentile' => 95, 'window_seconds' => 300, 'min_samples' => 5],
            'workers' => ['min' => 1, 'max' => 1, 'scalable' => false],
        ],
    ]);

    return app(AutoscaleManager::class);
}

test('a pinned queue under a cluster target leaves breach events to the leader', function (): void {
    $manager = pinnedLedgerManager();
    $this->pickups->seedRepeated(10, 90.0, 'sqs', 'ledger');
    $config = QueueConfiguration::fromConfig('sqs', 'ledger');
    $supervise = new ReflectionMethod($manager, 'superviseQueue');

    $supervise->invoke($manager, $config, QueueMetricsFactory::onSqs(30, 'ledger'), 0);

    Event::assertNotDispatched(SlaBreached::class);
    // The console still shows what this host measured.
    expect(breachQueueStats($manager, 'sqs:ledger')->slaStatus)->toBe('breached');

    // And no breach state was remembered, so the pickups clearing is not a
    // recovery this host announces either.
    $this->pickups->clear('sqs', 'ledger');
    $supervise->invoke($manager, $config, QueueMetricsFactory::onSqs(0, 'ledger'), 0);

    Event::assertNotDispatched(SlaRecovered::class);
    expect(breachQueueStats($manager, 'sqs:ledger')->slaStatus)->toBe('ok');
});

test('a pinned queue held at the host worker ceiling still reports its breach', function (): void {
    // One worker on another queue fills the host's single slot, so the
    // ledger's missing worker cannot be spawned and supervision returns early.
    config()->set('queue-autoscale.limits.max_total_workers', 1);
    $manager = pinnedLedgerManager();
    $pool = (new ReflectionProperty($manager, 'pool'))->getValue($manager);
    $pool->add(new class(new Process(['true']), 'sqs', 'other', now()) extends WorkerProcess
    {
        public function isRunning(): bool
        {
            return true;
        }

        public function isTerminating(): bool
        {
            return false;
        }
    });
    $this->pickups->seedRepeated(10, 90.0, 'sqs', 'ledger');

    (new ReflectionMethod($manager, 'superviseQueue'))
        ->invoke($manager, QueueConfiguration::fromConfig('sqs', 'ledger'), QueueMetricsFactory::onSqs(30, 'ledger'), null);

    Event::assertDispatchedTimes(SlaBreached::class, 1);
    expect(breachQueueStats($manager, 'sqs:ledger')->slaStatus)->toBe('breached')
        ->and($pool->totalCount())->toBe(1);
});
