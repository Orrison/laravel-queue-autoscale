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

test('skips every in-process or discarding driver, whatever the connection is called', function (): void {
    config()->set('queue.connections.null', ['driver' => 'null']);
    config()->set('queue.connections.background', ['driver' => 'background']);
    config()->set('queue.connections.inline', ['driver' => 'sync']);
    config()->set('queue.connections.sqs', ['driver' => 'sqs']);

    fakeDiscoveredQueues([
        'null:dropped' => rawDiscoveredMetrics('null', 'dropped'),
        'background:bg' => rawDiscoveredMetrics('background', 'bg'),
        'inline:reports' => rawDiscoveredMetrics('inline', 'reports'),
        'sqs:emails' => rawDiscoveredMetrics('sqs', 'emails'),
    ]);

    $discovered = app(WorkloadDiscovery::class)->discover();

    expect(array_keys($discovered->queues))->toBe(['sqs:emails']);
});

test('keeps a metrics entry that does not name its connection', function (): void {
    $metrics = rawDiscoveredMetrics('redis', 'default');
    unset($metrics['connection']);

    fakeDiscoveredQueues(['redis:default' => $metrics]);

    $discovered = app(WorkloadDiscovery::class)->discover();

    expect(array_keys($discovered->queues))->toBe(['redis:default']);
});

test('still supervises a configured queue whose metrics came from a sync run', function (): void {
    // The sync entry is dropped, but the queue the operator declared on a
    // worked connection is fetched directly and supervised.
    config()->set('queue-autoscale.queues', ['emails' => ['connection' => 'redis']]);

    fakeDiscoveredQueues([
        'sync:emails' => rawDiscoveredMetrics('sync', 'emails'),
    ]);

    $discovered = app(WorkloadDiscovery::class)->discover();

    expect(array_keys($discovered->queues))->toBe(['redis:emails']);
});
