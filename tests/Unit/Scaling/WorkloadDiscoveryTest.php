<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\WorkloadDiscovery;

beforeEach(function (): void {
    stubMetricsRecalculation();

    config()->set('queue.connections.redis', ['driver' => 'redis', 'queue' => 'default']);
    config()->set('queue.connections.sync', ['driver' => 'sync']);
    config()->set('queue.connections.deferred', ['driver' => 'deferred']);
});

test('does not discover workloads on connections no worker can pop from', function (): void {
    fakeDiscoveredQueues([
        'redis:default' => rawDiscoveredMetrics('redis', 'default'),
        'sync:sync' => rawDiscoveredMetrics('sync', 'sync'),
        'deferred:sync' => rawDiscoveredMetrics('deferred', 'sync'),
    ]);

    $discovered = app(WorkloadDiscovery::class)->discover();

    expect(array_keys($discovered->queues))->toBe(['redis:default']);
});

test('still discovers a real queue named sync on a worked connection', function (): void {
    fakeDiscoveredQueues([
        'redis:sync' => rawDiscoveredMetrics('redis', 'sync'),
    ]);

    $discovered = app(WorkloadDiscovery::class)->discover();

    expect(array_keys($discovered->queues))->toBe(['redis:sync']);
});
