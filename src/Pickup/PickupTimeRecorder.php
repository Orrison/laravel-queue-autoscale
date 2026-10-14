<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Pickup;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;
use Cbox\LaravelQueueAutoscale\Support\QueueName;
use Illuminate\Queue\Events\JobProcessing;

class PickupTimeRecorder
{
    public function __construct(
        private readonly PickupTimeStoreContract $store,
        private readonly PickupSampler $sampler,
    ) {}

    public function handle(JobProcessing $event): void
    {
        $payload = $event->job->payload();
        $pushedAt = $payload['pushedAt'] ?? null;

        if (! is_numeric($pushedAt)) {
            return;
        }

        // A released or retried job keeps its first push's pushedAt, so a later
        // attempt's sample would include earlier runs and their backoff.
        if ($event->job->attempts() > 1) {
            return;
        }

        $now = microtime(true);
        $availableAt = (float) $pushedAt + (is_numeric($payload['delay'] ?? null) ? (float) $payload['delay'] : 0.0);
        $pickupSeconds = max(0.0, $now - $availableAt);

        $maxSampleSeconds = AutoscaleConfiguration::pickupMaxSampleSeconds();

        if ($maxSampleSeconds !== null && $pickupSeconds > $maxSampleSeconds) {
            return;
        }

        $queue = QueueName::normalize($event->connectionName, $event->job->getQueue() ?: 'default');

        // Sampled per queue, not per process: a group worker polls several at
        // once, and one shared counter let a busy queue set the probability
        // for the quiet queue beside it.
        if (! $this->sampler->shouldRecord($event->connectionName, $queue)) {
            return;
        }

        $this->store->record(
            connection: $event->connectionName,
            queue: $queue,
            timestamp: $now,
            pickupSeconds: $pickupSeconds,
        );
    }
}
