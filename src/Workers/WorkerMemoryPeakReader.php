<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Workers;

/**
 * Reads a worker's peak resident memory since it started (Linux `VmHWM`), and
 * its current resident memory (`VmRSS`).
 *
 * The per-job memory that queue-metrics records is sampled while a job runs,
 * so it misses the peak of a worker booting: loading the framework and
 * compiling the application into its OPcache before the first job. That boot
 * peak is when a host packed to its limit gets OOM-killed. `VmHWM` covers the
 * whole life of the process, boot included.
 *
 * Returns null where `/proc` is not available (macOS, BSD, Windows).
 */
class WorkerMemoryPeakReader
{
    public function __construct(
        private readonly string $procRoot = '/proc',
    ) {}

    public function peakMb(int $pid): ?float
    {
        return $this->statusFieldMb($pid, 'VmHWM');
    }

    /**
     * Memory the worker holds right now: the part of its cost that is already
     * counted in the host's or cgroup's measured usage.
     */
    public function residentMb(int $pid): ?float
    {
        return $this->statusFieldMb($pid, 'VmRSS');
    }

    private function statusFieldMb(int $pid, string $field): ?float
    {
        $contents = @file_get_contents("{$this->procRoot}/{$pid}/status");

        if ($contents === false || ! preg_match("/^{$field}:\\s+(\\d+)\\s+kB$/m", $contents, $matches)) {
            return null;
        }

        return (int) $matches[1] / 1024;
    }
}
