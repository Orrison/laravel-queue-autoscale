<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\DTOs;

/**
 * What a set of live workers typically peaks at, and how many were sampled.
 *
 * Each sample is a worker's `VmHWM`: its peak resident memory over its whole
 * life. That makes a single worker that once ran an unusually heavy job an
 * outlier forever, so the statistic must not let one worker set the
 * per-worker cost for every other worker on the queue.
 *
 * - Ten or more samples: the nearest-rank 90th percentile. One outlier in ten
 *   sits above it, so it is ignored, while a peak most workers share is kept.
 * - Fewer than ten: the nearest-rank p90 is simply the maximum there, so the
 *   lower median is used instead. The boot peak this floor exists to catch is
 *   shared by every worker, so the typical worker already carries it; the
 *   lower median of two or more samples is never the highest one, so a single
 *   outlier cannot move it.
 */
readonly class WorkerPeakMemory
{
    private const PERCENTILE_MIN_SAMPLES = 10;

    public function __construct(
        public float $mb = 0.0,
        public int $workers = 0,
    ) {}

    /**
     * @param  non-empty-list<float>  $peaksMb
     */
    public static function fromPeaks(array $peaksMb): self
    {
        sort($peaksMb);
        $count = count($peaksMb);

        $index = $count >= self::PERCENTILE_MIN_SAMPLES
            ? (int) ceil($count * 0.9) - 1
            : intdiv($count - 1, 2);

        return new self(mb: $peaksMb[$index], workers: $count);
    }
}
