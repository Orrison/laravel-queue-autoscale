<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Testing;

use Carbon\Carbon;
use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;

/**
 * Test double for the pickup-time store, so a spec can put a pickup
 * percentile in front of the strategy without Redis or real jobs.
 *
 * The window is measured against Carbon's clock rather than the wall clock,
 * so `Carbon::setTestNow()` ages samples out of the SLA window exactly the way
 * real time ages them out of the Redis store — which is what a spec about
 * recovery needs to drive.
 */
class InMemoryPickupTimeStore implements PickupTimeStoreContract
{
    /**
     * Samples are keyed by (connection, queue), as the real store keys them.
     *
     * @var array<string, list<array{timestamp: float, pickup_seconds: float}>>
     */
    private array $samples = [];

    public function record(string $connection, string $queue, float $timestamp, float $pickupSeconds): void
    {
        $this->samples[$this->key($connection, $queue)][] = [
            'timestamp' => $timestamp,
            'pickup_seconds' => $pickupSeconds,
        ];
    }

    public function recentSamples(string $connection, string $queue, int $windowSeconds): array
    {
        $cutoff = $this->now() - $windowSeconds;

        return array_values(array_filter(
            $this->samples[$this->key($connection, $queue)] ?? [],
            static fn (array $sample): bool => $sample['timestamp'] >= $cutoff,
        ));
    }

    /**
     * Record one sample per pickup time given, all stamped "now".
     *
     * @param  list<float|int>  $pickupSeconds
     */
    public function seed(array $pickupSeconds, string $connection = 'redis', string $queue = 'default'): void
    {
        foreach ($pickupSeconds as $seconds) {
            $this->record($connection, $queue, $this->now(), (float) $seconds);
        }
    }

    /**
     * Record `$count` samples that all waited `$pickupSeconds`.
     */
    public function seedRepeated(int $count, float $pickupSeconds, string $connection = 'redis', string $queue = 'default'): void
    {
        $this->seed(array_fill(0, max(0, $count), $pickupSeconds), $connection, $queue);
    }

    /**
     * Forget every sample for one queue, as if none had ever been picked up.
     */
    public function clear(string $connection = 'redis', string $queue = 'default'): void
    {
        unset($this->samples[$this->key($connection, $queue)]);
    }

    private function now(): float
    {
        return (float) Carbon::now()->format('U.u');
    }

    private function key(string $connection, string $queue): string
    {
        return "{$connection}:{$queue}";
    }
}
