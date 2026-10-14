<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Pickup;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;

/**
 * Payload hook that stamps `pushedAt` on dispatch, so pickup time can be
 * measured without Horizon. Opt-in via `pickup_time.stamp_pushed_at`; a
 * payload that already carries `pushedAt` keeps it.
 */
class PushedAtStamper
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function __invoke(string $connection, mixed $queue, array $payload): array
    {
        if (isset($payload['pushedAt']) || ! AutoscaleConfiguration::stampPushedAt()) {
            return [];
        }

        return ['pushedAt' => microtime(true)];
    }
}
