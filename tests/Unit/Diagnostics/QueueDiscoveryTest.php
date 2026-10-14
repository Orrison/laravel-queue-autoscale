<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Diagnostics\QueueDiscovery;

/**
 * @param  array<string, array<string, mixed>>  $metrics
 */
function discoveryReading(array $metrics): QueueDiscovery
{
    return new class($metrics) extends QueueDiscovery
    {
        /**
         * @param  array<string, array<string, mixed>>  $metrics
         */
        public function __construct(private array $metrics) {}

        protected function fromMetrics(): array
        {
            return $this->metrics;
        }
    };
}

beforeEach(function (): void {
    config()->set('queue-autoscale.queues', []);
    config()->set('queue.connections.redis', ['driver' => 'redis']);
    config()->set('queue.connections.sync', ['driver' => 'sync']);
    config()->set('queue.connections.null', ['driver' => 'null']);
    config()->set('queue.connections.deferred', ['driver' => 'deferred']);
    config()->set('queue.connections.background', ['driver' => 'background']);
    config()->set('queue.connections.inline', ['driver' => 'sync']);
});

test('queues on connections no worker can pop from are not discovered', function (): void {
    $discovery = discoveryReading([
        'redis:emails' => ['queue' => 'emails', 'connection' => 'redis'],
        'sync:sync' => ['queue' => 'sync', 'connection' => 'sync'],
        'null:dropped' => ['queue' => 'dropped', 'connection' => 'null'],
        'deferred:later' => ['queue' => 'later', 'connection' => 'deferred'],
        'background:bg' => ['queue' => 'bg', 'connection' => 'background'],
    ]);

    expect($discovery->names())->toBe(['emails']);
});

test('the connection is judged by its driver, not its name', function (): void {
    // A real queue named "sync" on a worked connection is still a queue,
    // and a sync-driver connection under another name is still skipped.
    $discovery = discoveryReading([
        'redis:sync' => ['queue' => 'sync', 'connection' => 'redis'],
        'inline:reports' => ['queue' => 'reports', 'connection' => 'inline'],
    ]);

    expect($discovery->names())->toBe(['sync']);
});

test('the connection is read from the key when the payload lacks it', function (): void {
    $discovery = discoveryReading([
        'sync:sync' => ['queue' => 'sync'],
        'redis:emails' => [],
    ]);

    expect($discovery->names())->toBe(['emails']);
});
