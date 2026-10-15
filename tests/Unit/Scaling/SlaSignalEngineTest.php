<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;
use Cbox\LaravelQueueAutoscale\Contracts\SlaSignalStrategy;
use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Cbox\LaravelQueueAutoscale\Fuse\FailureFuse;
use Cbox\LaravelQueueAutoscale\Fuse\NullFailureWindowStore;
use Cbox\LaravelQueueAutoscale\Pickup\SortBasedPercentileCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\ArrivalRateEstimator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\BacklogDrainCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LittlesLawCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\ResourceEstimateResolver;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingEngine;
use Cbox\LaravelQueueAutoscale\Scaling\SlaSignal;
use Cbox\LaravelQueueAutoscale\Scaling\SlaSignalResolver;
use Cbox\LaravelQueueAutoscale\Scaling\SlaSignalSource;
use Cbox\LaravelQueueAutoscale\Scaling\Strategies\HybridStrategy;
use Cbox\LaravelQueueAutoscale\Testing\InMemoryPickupTimeStore;
use Cbox\LaravelQueueAutoscale\Testing\QueueMetricsFactory;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;
use Illuminate\Support\Carbon;

/**
 * The strategy measures the SLA signal once; the engine hands that same
 * measurement to whoever judges breach. These pin both halves, and the
 * fallback that keeps a strategy written against the plain contract exactly
 * where it was.
 */
function signalHybrid(InMemoryPickupTimeStore $store): HybridStrategy
{
    return new HybridStrategy(
        littles: new LittlesLawCalculator,
        backlog: new BacklogDrainCalculator,
        arrivalEstimator: new ArrivalRateEstimator,
        spawnTracker: new class implements SpawnLatencyTrackerContract
        {
            public function recordSpawn(string $workerId, string $connection, string $queue, SpawnCompensationConfiguration $config): void {}

            public function recordFirstPickup(string $workerId, float $pickupTimestamp): void {}

            public function currentLatency(string $connection, string $queue, SpawnCompensationConfiguration $config): float
            {
                return 0.0;
            }
        },
        pickupStore: $store,
        percentileCalc: new SortBasedPercentileCalculator,
    );
}

function signalEngine(ScalingStrategyContract $strategy, ?InMemoryPickupTimeStore $store = null): ScalingEngine
{
    return new ScalingEngine(
        $strategy,
        new CapacityCalculator,
        new ResourceEstimateResolver,
        new FailureFuse(new NullFailureWindowStore),
        $store === null ? null : new SlaSignalResolver($store, new SortBasedPercentileCalculator),
    );
}

function plainStrategy(): ScalingStrategyContract
{
    return new class implements ScalingStrategyContract
    {
        public function calculateTargetWorkers(QueueMetricsData $metrics, QueueConfiguration $config): int
        {
            return 3;
        }

        public function getLastReason(): string
        {
            return 'plain';
        }

        public function getLastPrediction(): ?float
        {
            return null;
        }
    };
}

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00'));
    $this->store = new InMemoryPickupTimeStore;
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('the hybrid strategy reports the p95 it scaled on', function (): void {
    $this->store->seedRepeated(20, 48.0);
    $strategy = signalHybrid($this->store);

    $strategy->calculateTargetWorkers(QueueMetricsFactory::backlogged(100), makeQueueConfig());

    expect($strategy)->toBeInstanceOf(SlaSignalStrategy::class)
        ->and($strategy->getLastSlaSignal())
        ->source->toBe(SlaSignalSource::PickupPercentile)
        ->seconds->toBe(48.0);

    // The same number appears in the reason the strategy gives for its target.
    expect($strategy->getLastReason())->toContain('p95=48.0s');
});

test('the hybrid strategy reports an oldest-age fallback when the window is short', function (): void {
    $strategy = signalHybrid($this->store);

    $strategy->calculateTargetWorkers(QueueMetricsFactory::behind(100, oldestJobAge: 25), makeQueueConfig());

    expect($strategy->getLastSlaSignal())
        ->source->toBe(SlaSignalSource::OldestJobAgeFallback)
        ->seconds->toBe(25.0);
});

test('the hybrid strategy has no signal before its first calculation', function (): void {
    expect(signalHybrid($this->store)->getLastSlaSignal())->toBeNull();
});

test('a signal never leaks from one queue to the next', function (): void {
    $this->store->seedRepeated(20, 48.0, 'redis', 'slow');
    $strategy = signalHybrid($this->store);

    $strategy->calculateTargetWorkers(QueueMetricsFactory::backlogged(10, 'slow'), makeQueueConfig(['queue' => 'slow']));
    $strategy->calculateTargetWorkers(QueueMetricsFactory::backlogged(10, 'fast'), makeQueueConfig(['queue' => 'fast']));

    expect($strategy->getLastSlaSignal())
        ->source->toBe(SlaSignalSource::OldestJobAgeFallback)
        ->seconds->toBe(1.0);
});

test('a decision from the engine carries the strategy signal', function (): void {
    $this->store->seedRepeated(20, 48.0, 'sqs', 'default');
    $engine = signalEngine(signalHybrid($this->store));

    $decision = $engine->evaluate(QueueMetricsFactory::onSqs(100), makeQueueConfig(['connection' => 'sqs']), currentWorkers: 1);

    expect($decision->slaSignal)
        ->source->toBe(SlaSignalSource::PickupPercentile)
        ->seconds->toBe(48.0);
});

test('a strategy without a signal yields the oldest job age, exactly as before', function (): void {
    $engine = signalEngine(plainStrategy(), $this->store);
    $metrics = QueueMetricsFactory::behind(10, oldestJobAge: 33);

    expect($engine->evaluate($metrics, makeQueueConfig(), currentWorkers: 1)->slaSignal)
        ->toEqual(SlaSignal::fromOldestJobAge(33));

    expect($engine->assessDemand($metrics, makeQueueConfig())->slaSignal)
        ->toEqual(SlaSignal::fromOldestJobAge(33));
});

test('a strategy that opts in but reports null also falls back to the oldest job age', function (): void {
    $strategy = new class implements SlaSignalStrategy
    {
        public function calculateTargetWorkers(QueueMetricsData $metrics, QueueConfiguration $config): int
        {
            return 1;
        }

        public function getLastReason(): string
        {
            return '';
        }

        public function getLastPrediction(): ?float
        {
            return null;
        }

        public function getLastSlaSignal(): ?SlaSignal
        {
            return null;
        }
    };

    expect(signalEngine($strategy)->assessDemand(QueueMetricsFactory::behind(5, oldestJobAge: 9), makeQueueConfig())->slaSignal)
        ->toEqual(SlaSignal::fromOldestJobAge(9));
});

test('a custom strategy can report its own signal', function (): void {
    $strategy = new class implements SlaSignalStrategy
    {
        public function calculateTargetWorkers(QueueMetricsData $metrics, QueueConfiguration $config): int
        {
            return 1;
        }

        public function getLastReason(): string
        {
            return '';
        }

        public function getLastPrediction(): ?float
        {
            return null;
        }

        public function getLastSlaSignal(): ?SlaSignal
        {
            return SlaSignal::fromPickupPercentile(77.0, 90);
        }
    };

    expect(signalEngine($strategy)->evaluate(QueueMetricsFactory::onSqs(5), makeQueueConfig(), 0)->slaSignal)
        ->toEqual(SlaSignal::fromPickupPercentile(77.0, 90));
});

test('the engine judges on the head job when it is older than the strategy percentile', function (): void {
    $this->store->seedRepeated(20, 1.0);
    $strategy = signalHybrid($this->store);
    $metrics = QueueMetricsFactory::behind(500, oldestJobAge: 200);

    $decision = signalEngine($strategy)->evaluate($metrics, makeQueueConfig(), currentWorkers: 1);

    expect($decision->slaSignal)->toEqual(SlaSignal::fromOldestJobAge(200))
        ->and(signalEngine($strategy)->assessDemand($metrics, makeQueueConfig())->slaSignal)
        ->toEqual(SlaSignal::fromOldestJobAge(200))
        // The strategy itself still reports, and scaled on, its percentile.
        ->and($strategy->getLastSlaSignal())->toEqual(SlaSignal::fromPickupPercentile(1.0, 95));
});

test('a pinned workload is judged on the head job when it is older than the percentile', function (): void {
    $this->store->seedRepeated(20, 1.0);

    $signal = signalEngine(signalHybrid($this->store), $this->store)
        ->measureSlaSignal(QueueMetricsFactory::behind(10, oldestJobAge: 90), makeQueueConfig());

    expect($signal)->toEqual(SlaSignal::fromOldestJobAge(90));
});

test('assessDemand returns the same target as evaluateDemand', function (): void {
    $this->store->seedRepeated(20, 48.0);
    $metrics = QueueMetricsFactory::behind(500, oldestJobAge: 40);
    $config = makeQueueConfig(['maxWorkers' => 50]);

    $assessed = signalEngine(signalHybrid($this->store))->assessDemand($metrics, $config);
    $evaluated = signalEngine(signalHybrid($this->store))->evaluateDemand($metrics, $config);

    expect($assessed->targetWorkers)->toBe($evaluated)
        ->and($assessed->slaSignal->seconds)->toBe(48.0);
});

test('a pinned workload is measured with the resolver when the strategy reports signals', function (): void {
    $this->store->seedRepeated(20, 48.0, 'sqs', 'default');

    $signal = signalEngine(signalHybrid($this->store), $this->store)
        ->measureSlaSignal(QueueMetricsFactory::onSqs(10), makeQueueConfig(['connection' => 'sqs']));

    expect($signal)->toEqual(SlaSignal::fromPickupPercentile(48.0, 95));
});

test('a pinned workload is judged on the oldest job age when the strategy reports none', function (): void {
    $this->store->seedRepeated(20, 48.0);

    $signal = signalEngine(plainStrategy(), $this->store)
        ->measureSlaSignal(QueueMetricsFactory::behind(10, oldestJobAge: 7), makeQueueConfig());

    expect($signal)->toEqual(SlaSignal::fromOldestJobAge(7));
});

test('an engine built without a resolver judges pinned workloads on the oldest job age', function (): void {
    $this->store->seedRepeated(20, 48.0);

    $signal = signalEngine(signalHybrid($this->store))
        ->measureSlaSignal(QueueMetricsFactory::behind(10, oldestJobAge: 7), makeQueueConfig());

    expect($signal)->toEqual(SlaSignal::fromOldestJobAge(7));
});

test('the container wires the resolver into the engine', function (): void {
    $this->store->seedRepeated(20, 48.0, 'sqs', 'default');
    app()->instance(PickupTimeStoreContract::class, $this->store);
    app()->forgetInstance(ScalingStrategyContract::class);
    app()->forgetInstance(SlaSignalResolver::class);
    app()->forgetInstance(ScalingEngine::class);

    $signal = app(ScalingEngine::class)->measureSlaSignal(QueueMetricsFactory::onSqs(10), makeQueueConfig(['connection' => 'sqs']));

    expect($signal->source)->toBe(SlaSignalSource::PickupPercentile);
});
