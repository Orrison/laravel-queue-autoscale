<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Cluster\ClusterManagerState;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\ConnectionLimitedProfile;
use Cbox\LaravelQueueAutoscale\Contracts\ClusterStoreContract;
use Cbox\LaravelQueueAutoscale\Testing\InMemoryFailureWindowStore;
use Cbox\LaravelQueueAutoscale\Testing\InMemoryPickupTimeStore;
use Cbox\LaravelQueueAutoscale\Testing\InteractsWithAutoscaling;
use Cbox\LaravelQueueAutoscale\Testing\QueueMetricsFactory;

/**
 * The helpers shipped in src/Testing, exercised the way a consumer would use
 * them.
 *
 * Dogfooding is the point: an assertion that is awkward here would be worse in
 * an application that does not know the internals, so anything clumsy in this
 * file is a defect in the helper rather than in the spec.
 */
uses(InteractsWithAutoscaling::class);

test('a consumer can assert what their configuration demands', function (): void {
    config()->set('queue-autoscale.queues', [
        'scrape-tenant-*' => [
            'profile' => ConnectionLimitedProfile::class,
            'workers' => ['max' => 5],
        ],
    ]);

    // The question an application actually has: given this queue in this
    // state, how many workers does my configuration ask for?
    $this->assertWorkersDemanded(
        0,
        QueueMetricsFactory::idle('scrape-tenant-42'),
    );

    $this->assertWorkersDemanded(
        5,
        QueueMetricsFactory::behind(1_000, oldestJobAge: 300, queue: 'scrape-tenant-42'),
    );
});

test('a connection limit can be asserted as a cap rather than a number', function (): void {
    // What a downstream limit really needs proving: not that a given backlog
    // produces a given number, but that no backlog produces more than the cap.
    config()->set('queue-autoscale.queues', [
        'scrape-tenant-*' => [
            'profile' => ConnectionLimitedProfile::class,
            'workers' => ['max' => 5],
        ],
    ]);

    $this->assertWorkersCappedAt(5, 'scrape-tenant-42');
});

test('the fuse can be tripped without waiting for real failures', function (): void {
    config()->set('queue-autoscale.queues', [
        'payments' => [
            'profile' => ConnectionLimitedProfile::class,
            'workers' => ['min' => 0, 'max' => 8],
        ],
    ]);

    $behind = QueueMetricsFactory::behind(1_000, oldestJobAge: 300, queue: 'payments');

    $this->fakeFailureWindows();
    expect($this->workersDemandedFor($behind))->toBeGreaterThan(0);

    // Once the downstream dependency is failing, the same backlog must stop
    // asking for workers.
    $this->tripFuseFor('payments');

    expect($this->workersDemandedFor($behind))->toBe(0);
});

test('tripping the fuse keeps the window store a spec already installed', function (): void {
    config()->set('queue-autoscale.queues', [
        'payments' => ['profile' => ConnectionLimitedProfile::class, 'workers' => ['min' => 0, 'max' => 8]],
    ]);

    $installed = $this->fakeFailureWindows();

    // A spec that seeds its own window and then trips the fuse must still be
    // holding the store it seeded, not a replacement that dropped that state.
    expect($this->tripFuseFor('payments'))->toBe($installed);
});

test('tripping the fuse installs a window store when a spec has not', function (): void {
    config()->set('queue-autoscale.queues', [
        'payments' => ['profile' => ConnectionLimitedProfile::class, 'workers' => ['min' => 0, 'max' => 8]],
    ]);

    expect($this->tripFuseFor('payments'))->toBeInstanceOf(InMemoryFailureWindowStore::class);
});

test('cluster state can be assembled without redis', function (): void {
    $cluster = $this->fakeCluster();

    $cluster->withManager(new ClusterManagerState(
        managerId: 'a', host: 'web-01', lastSeenAt: 0, totalWorkers: 3, maxWorkers: 10,
        availableWorkerCapacity: 7, capacityLimiter: 'cpu', cpuPercent: 20.0, cpuCores: 4.0,
        cpuUsableCores: 3.6, cpuReservedCores: 0.4, memoryPercent: 30.0, memoryTotalMb: 4096.0,
        memoryUsedMb: 1228.8, memoryFreeMb: 2867.2, queueCount: 1, groupCount: 0,
        packageVersion: '4.0.0', queueWorkers: [], groupWorkers: [],
    ))->withLeader('a');

    expect(app(ClusterStoreContract::class))->toBe($cluster)
        ->and($cluster->activeManagers())->toHaveCount(1)
        ->and($cluster->isLeader('a'))->toBeTrue()
        ->and($cluster->isLeader('b'))->toBeFalse()
        ->and($cluster->leaderToken())->not->toBeNull();
});

test('deregistering the leader releases the lease', function (): void {
    $cluster = $this->fakeCluster()->withLeader('a');

    $cluster->deregister('a');

    expect($cluster->leaderId())->toBeNull()
        ->and($cluster->leaderToken())->toBeNull()
        // And the next manager to ask can take it.
        ->and($cluster->isLeader('b'))->toBeTrue();
});

test('the metrics factory distinguishes a full queue from a late one', function (): void {
    // The distinction that decides the target: the drain calculation
    // contributes nothing until the SLA budget is half spent, so a deep queue
    // and a late queue are not the same input.
    $backlogged = QueueMetricsFactory::backlogged(500);
    $behind = QueueMetricsFactory::behind(500, oldestJobAge: 600);

    expect($backlogged->pending)->toBe($behind->pending)
        ->and($backlogged->oldestJobAge)->toBeLessThan($behind->oldestJobAge)
        ->and($behind->ageStatus)->toBe('critical');
});

test('a consumer can assert an SQS queue breaches on its measured pickup time', function (): void {
    config()->set('queue-autoscale.queues', [
        'orders' => ['sla' => ['target_seconds' => 30, 'min_samples' => 5]],
    ]);

    $pickups = $this->fakePickupTimes();
    $queue = QueueMetricsFactory::onSqs(200, 'orders');

    // SQS reports no oldest-job age, so with no pickups measured there is
    // nothing to breach on.
    $this->assertWithinSla($queue);

    $pickups->seedRepeated(20, 45.0, 'sqs', 'orders');

    $this->assertBreachesSla($queue);
    expect($this->slaSignalFor($queue)->describe())->toBe('p95 pickup=45.0s');
});

test('the SQS metrics factory models a driver without an oldest-job age', function (): void {
    $sqs = QueueMetricsFactory::onSqs(300, 'orders', activeWorkers: 4);

    expect($sqs->connection)->toBe('sqs')
        ->and($sqs->driver)->toBe('sqs')
        ->and($sqs->queue)->toBe('orders')
        ->and($sqs->pending)->toBe(300)
        ->and($sqs->oldestJobAge)->toBe(0)
        ->and($sqs->activeWorkers)->toBe(4);
});

test('the in-memory pickup store ages samples out on the test clock', function (): void {
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-10-14 09:00:00'));

    $store = new InMemoryPickupTimeStore;
    $store->seed([1.0, 2.0], 'sqs', 'orders');

    expect($store->recentSamples('sqs', 'orders', 60))->toHaveCount(2)
        ->and($store->recentSamples('sqs', 'other', 60))->toBe([]);

    Carbon\Carbon::setTestNow(now()->addSeconds(61));

    expect($store->recentSamples('sqs', 'orders', 60))->toBe([]);

    $store->seedRepeated(3, 4.0, 'sqs', 'orders');
    $store->clear('sqs', 'orders');

    expect($store->recentSamples('sqs', 'orders', 600))->toBe([]);

    Carbon\Carbon::setTestNow();
});
