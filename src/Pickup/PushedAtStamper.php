<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Pickup;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;

/**
 * Payload hook that stamps `pushedAt` on dispatch, so pickup time can be
 * measured without Horizon. Opt-in via `pickup_time.stamp_pushed_at`.
 *
 * A payload that already carries `pushedAt` when the hook runs keeps it.
 * Horizon is not that case: it writes its own `pushedAt` when it pushes the
 * job, after payload hooks have run, overwriting the stamp with a timestamp
 * from the same moment — so the outcome is the same either way.
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
