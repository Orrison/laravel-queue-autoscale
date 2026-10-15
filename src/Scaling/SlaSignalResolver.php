<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling;

use Carbon\Carbon;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\PercentileCalculatorContract;
use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;

/**
 * Measures a workload's SLA signal: the configured pickup-time percentile over
 * the SLA window, or the oldest job's age when the window has too few samples.
 *
 * The one place this measurement is defined. HybridStrategy scales on it and
 * reports what it measured, so breach state reads the same number the target
 * was computed from; pinned workloads, which never run the strategy, are
 * measured here directly so they are judged the same way.
 */
class SlaSignalResolver
{
    public function __construct(
        private readonly PickupTimeStoreContract $pickupStore,
        private readonly PercentileCalculatorContract $percentileCalc,
    ) {}

    /**
     * For a group-adapted configuration the samples of every member queue are
     * pooled: the recorder stores samples under the real queue a job ran on,
     * never under the group's name, so reading the group's own key would always
     * come back empty.
     */
    public function resolve(QueueMetricsData $metrics, QueueConfiguration $config): SlaSignal
    {
        $pickupTimes = [];
        $lastPickupAt = null;

        foreach ($config->sampleQueues() as $sampleQueue) {
            $samples = $this->pickupStore->recentSamples(
                $config->connection,
                $sampleQueue,
                $config->sla->windowSeconds,
            );

            foreach ($samples as $sample) {
                $pickupTimes[] = $sample['pickup_seconds'];
                $lastPickupAt = max($lastPickupAt ?? $sample['timestamp'], $sample['timestamp']);
            }
        }

        $percentile = $this->percentileCalc->compute(
            $pickupTimes,
            $config->sla->percentile,
            $config->sla->minSamples,
        );

        if ($percentile === null || $lastPickupAt === null) {
            return SlaSignal::fromOldestJobAgeFallback($metrics->oldestJobAge);
        }

        // Carbon's clock rather than microtime(): the same instant on a real
        // run, and the one a spec can move.
        $now = (float) Carbon::now()->format('U.u');

        return SlaSignal::fromPickupPercentile(
            $percentile,
            $config->sla->percentile,
            max(0.0, $now - $lastPickupAt),
        );
    }
}
