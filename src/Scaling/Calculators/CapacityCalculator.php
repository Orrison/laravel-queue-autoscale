<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\Calculators;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\CapacityCalculationResult;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\LimitingFactor;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\ResourceEstimate;
use Cbox\SystemMetrics\DTO\Metrics\Cpu\CpuSnapshot;
use Cbox\SystemMetrics\DTO\Metrics\SystemLimits;
use Cbox\SystemMetrics\SystemMetrics;
use Closure;

class CapacityCalculator
{
    /**
     * Cached system metrics so a tick that evaluates many queues reads the
     * host once rather than once per queue.
     */
    private ?float $cachedCpuPercent = null;

    /**
     * Memory limit and usage per scope that applies to this host, in MB.
     *
     * @var array<string, array{limit_mb: float, used_mb: float}>
     */
    private array $cachedMemoryScopes = [];

    private ?float $cachedAvailableCores = null;

    private ?float $cacheTimestamp = null;

    /**
     * Previous CPU counter snapshot, diffed against the current one to get a
     * usage percentage without sleeping. See measureCpuPercent().
     */
    private ?CpuSnapshot $previousCpuSnapshot = null;

    /** @var (Closure(): int)|null */
    private ?Closure $settlingWorkerCounter = null;

    /**
     * How long cached metrics remain valid (seconds).
     * Should be shorter than the evaluation interval to ensure fresh data each tick.
     */
    private const CACHE_TTL_SECONDS = 4.0;

    private const BYTES_PER_MB = 1024 * 1024;

    /**
     * Calculate maximum workers with detailed capacity breakdown
     *
     * Analyzes CPU and memory constraints separately and returns
     * comprehensive breakdown showing which factor is limiting.
     *
     * System metrics are cached per evaluation tick, so evaluating many
     * queues reads the host once.
     *
     * @param  int  $currentWorkers  Total workers currently running across all queues (for accurate capacity math)
     * @return CapacityCalculationResult Detailed capacity analysis with system-wide max workers
     */
    public function calculateMaxWorkers(int $currentWorkers, ResourceEstimate $estimate): CapacityCalculationResult
    {
        // Refresh system metrics if cache is stale or empty
        if (! $this->isCacheValid()) {
            $this->refreshSystemMetrics();
        }

        // If metrics refresh failed, return fallback
        if ($this->cachedAvailableCores === null) {
            return new CapacityCalculationResult(
                maxWorkersByCpu: 5,
                maxWorkersByMemory: 5,
                maxWorkersByConfig: PHP_INT_MAX,
                finalMaxWorkers: 5,
                limitingFactor: LimitingFactor::SystemMetricsUnavailable,
                details: [
                    'cpu_explanation' => 'system metrics unavailable - using fallback',
                    'memory_explanation' => 'system metrics unavailable - using fallback',
                    'error' => 'Failed to retrieve system limits',
                ]
            );
        }

        // CPU capacity calculation
        $maxCpuPercent = AutoscaleConfiguration::maxCpuPercent();
        $currentCpuPercent = $this->cachedCpuPercent ?? 50.0;

        $availableCpuPercent = max($maxCpuPercent - $currentCpuPercent, 0);
        $reserveCores = AutoscaleConfiguration::reserveCpuCores();
        $usableCores = max($this->cachedAvailableCores - $reserveCores, 0);

        $workerCpuCoreEstimate = max($estimate->cpuCoresPerWorker, 0.01);
        $cpuEstimateSource = $estimate->cpuSource->value;

        $availableCoreEquivalents = $usableCores * ($availableCpuPercent / 100);
        $additionalWorkersByCpu = (int) floor($availableCoreEquivalents / $workerCpuCoreEstimate);
        $maxWorkersByCpu = $currentWorkers + $additionalWorkersByCpu;

        // Memory capacity calculation. See memoryHeadroom() for which limit applies.
        $maxMemoryPercent = AutoscaleConfiguration::maxMemoryPercent();
        $workerMemoryMb = max($estimate->memoryMbPerWorker, 1.0);

        $memory = $this->memoryHeadroom($maxMemoryPercent);
        $totalMemoryMb = $memory['limit_mb'];
        $usedMemoryMb = $memory['used_mb'];
        $currentMemoryPercent = $totalMemoryMb > 0 ? ($usedMemoryMb / $totalMemoryMb) * 100 : 0.0;

        // A worker that has just started has not yet grown to its footprint, so
        // its eventual cost is charged now rather than read as free memory.
        // Counted on every call: workers spawned earlier in the same cycle are
        // not in the cached usage yet.
        $settlingWorkers = $this->settlingWorkerCounter !== null
            ? max(min(($this->settlingWorkerCounter)(), $currentWorkers), 0)
            : 0;
        $headroomMb = max($memory['headroom_mb'] - $settlingWorkers * $workerMemoryMb, 0.0);
        $availableMemoryPercent = $totalMemoryMb > 0 ? ($headroomMb / $totalMemoryMb) * 100 : 0.0;

        $additionalWorkersByMemory = (int) floor($headroomMb / $workerMemoryMb);
        $maxWorkersByMemory = $currentWorkers + $additionalWorkersByMemory;

        // Determine limiting factor and final capacity
        $finalMaxWorkers = max(min($maxWorkersByCpu, $maxWorkersByMemory), 0);

        $limitingFactor = match (true) {
            $maxWorkersByCpu < $maxWorkersByMemory => LimitingFactor::Cpu,
            $maxWorkersByMemory < $maxWorkersByCpu => LimitingFactor::Memory,
            default => LimitingFactor::Balanced,
        };

        // Build detailed explanation
        $details = [
            'cpu_explanation' => sprintf(
                '%d%% of %d cores, current usage: %.1f%%',
                (int) $maxCpuPercent,
                $this->cachedAvailableCores,
                $currentCpuPercent
            ),
            'memory_explanation' => sprintf(
                '%.1fGB available of %.1fGB (%s), %dMB/worker',
                $headroomMb / 1024,
                $totalMemoryMb / 1024,
                $memory['scope'].($memory['capped_by'] !== null ? ", capped by {$memory['capped_by']} free memory" : ''),
                $workerMemoryMb
            ),
            'cpu_details' => [
                'max_cpu_percent' => $maxCpuPercent,
                'current_cpu_percent' => $currentCpuPercent,
                'available_cpu_percent' => $availableCpuPercent,
                'total_cores' => $this->cachedAvailableCores,
                'reserve_cores' => $reserveCores,
                'usable_cores' => $usableCores,
                'worker_cpu_core_estimate' => $workerCpuCoreEstimate,
                'cpu_estimate_source' => $cpuEstimateSource,
            ],
            'memory_details' => [
                'max_memory_percent' => $maxMemoryPercent,
                'current_memory_percent' => $currentMemoryPercent,
                'available_memory_percent' => $availableMemoryPercent,
                'total_memory_mb' => $totalMemoryMb,
                'used_memory_mb' => $usedMemoryMb,
                'headroom_mb' => $headroomMb,
                'memory_scope' => $memory['scope'],
                'memory_capped_by' => $memory['capped_by'],
                'settling_workers' => $settlingWorkers,
                'worker_memory_mb' => $workerMemoryMb,
                'memory_estimate_source' => $estimate->memorySource->value,
            ],
        ];

        return new CapacityCalculationResult(
            maxWorkersByCpu: $maxWorkersByCpu,
            maxWorkersByMemory: $maxWorkersByMemory,
            maxWorkersByConfig: PHP_INT_MAX, // Will be set by ScalingEngine
            finalMaxWorkers: $finalMaxWorkers,
            limitingFactor: $limitingFactor,
            details: $details
        );
    }

    /**
     * Invalidate the cached metrics, forcing a fresh measurement on next call.
     * Useful when evaluation tick boundaries need explicit control.
     */
    public function invalidateCache(): void
    {
        $this->cacheTimestamp = null;
    }

    /**
     * Supply how many running workers were spawned too recently to have
     * reached their memory footprint. The manager owns the pool, so it
     * provides the count; without a counter none are charged.
     *
     * @param  (Closure(): int)|null  $counter
     */
    public function countSettlingWorkersWith(?Closure $counter): void
    {
        $this->settlingWorkerCounter = $counter;
    }

    /**
     * CPU usage since the previous evaluation tick.
     *
     * CPU counters are cumulative, so a usage percentage needs two snapshots
     * with time between them. SystemMetrics::cpuUsage() gets that time by
     * sleeping — a full blocking second inside the manager's control loop,
     * every cycle. On the default 5-second interval that burned a fifth of the
     * manager's wall clock and left every scaling decision computed from
     * metrics a second out of date, while making intervals below ~1.5s
     * impossible.
     *
     * Holding the previous snapshot gets the same measurement for free: the
     * gap between ticks is both cheaper AND a longer, steadier sample window
     * than the one-second sleep it replaces.
     *
     * The first tick of a process has nothing to diff against, so it falls
     * back to the neutral 50% the failure path already used rather than
     * reintroducing the sleep. One slightly-off cycle at start-up is a better
     * trade than a permanent per-cycle stall.
     */
    private function measureCpuPercent(): float
    {
        $snapshotResult = SystemMetrics::cpu();

        if ($snapshotResult->isFailure()) {
            return $this->cachedCpuPercent ?? 50.0;
        }

        $snapshot = $snapshotResult->getValue();
        $previous = $this->previousCpuSnapshot;
        $this->previousCpuSnapshot = $snapshot;

        if ($previous === null) {
            return 50.0;
        }

        $delta = CpuSnapshot::calculateDelta($previous, $snapshot);

        // Two snapshots taken within the same instant carry no signal; keep the
        // last known value rather than reporting a meaningless 0%.
        if ($delta->durationSeconds <= 0.0) {
            return $this->cachedCpuPercent ?? 50.0;
        }

        return $delta->usagePercentage();
    }

    private function isCacheValid(): bool
    {
        if ($this->cacheTimestamp === null) {
            return false;
        }

        return (microtime(true) - $this->cacheTimestamp) < self::CACHE_TTL_SECONDS;
    }

    private function refreshSystemMetrics(): void
    {
        $limitsResult = SystemMetrics::limits();
        if ($limitsResult->isFailure()) {
            $this->cachedAvailableCores = null;
            $this->cachedMemoryScopes = [];
            $this->cachedCpuPercent = null;
            $this->cacheTimestamp = null;

            return;
        }

        $limits = $limitsResult->getValue();
        $this->cachedAvailableCores = $limits->availableCpuCores();

        $this->cachedCpuPercent = $this->measureCpuPercent();

        $this->cachedMemoryScopes = $this->measureMemoryScopes($limits);

        $this->cacheTimestamp = microtime(true);
    }

    /**
     * Memory limit and usage in every scope that can stop this host's workers.
     *
     * - host: the kernel's MemTotal and MemAvailable, which already discounts
     *   reclaimable page cache. Always applies.
     * - cgroup: the container's own limit and usage, only when a limit is set.
     *   A cgroup without one says nothing about how much memory there is.
     * - configured: `limits.memory_limit_mb`, for a limit the process cannot
     *   see, such as one set only on a parent cgroup (a pod or task limit).
     *
     * Each scope pairs a limit with usage measured against that same limit.
     *
     * @return array<string, array{limit_mb: float, used_mb: float}>
     */
    private function measureMemoryScopes(SystemLimits $limits): array
    {
        $scopes = [];
        $hostUsedMb = null;
        $cgroupUsedMb = null;

        $memoryResult = SystemMetrics::memory();

        if ($memoryResult->isSuccess()) {
            $memory = $memoryResult->getValue();

            if ($memory->totalBytes > 0) {
                $hostUsedMb = self::toMb(max($memory->totalBytes - $memory->availableBytes, 0));
                $scopes['host'] = ['limit_mb' => self::toMb($memory->totalBytes), 'used_mb' => $hostUsedMb];
            }
        }

        $containerResult = SystemMetrics::container();

        if ($containerResult->isSuccess()) {
            $container = $containerResult->getValue();

            if ($container->memoryUsageBytes !== null) {
                $cgroupUsedMb = self::toMb($container->memoryUsageBytes);

                if ($container->hasMemoryLimit()) {
                    $scopes['cgroup'] = ['limit_mb' => self::toMb((int) $container->memoryLimitBytes), 'used_mb' => $cgroupUsedMb];
                }
            }
        }

        $configuredLimitMb = AutoscaleConfiguration::memoryLimitMb();
        $configuredUsedMb = $cgroupUsedMb ?? $hostUsedMb;

        if ($configuredLimitMb !== null && $configuredUsedMb !== null) {
            $scopes['configured'] = ['limit_mb' => $configuredLimitMb, 'used_mb' => $configuredUsedMb];
        }

        if ($scopes === []) {
            // Nothing readable: keep the long-standing assumption of half the
            // reported memory in use.
            $totalMb = $limits->memoryBytes > 0 ? self::toMb($limits->memoryBytes) : 4096.0;
            $scopes['fallback'] = ['limit_mb' => $totalMb, 'used_mb' => $totalMb * 0.5];
        }

        return $scopes;
    }

    private static function toMb(int|float $bytes): float
    {
        return $bytes / self::BYTES_PER_MB;
    }

    /**
     * Memory headroom under the configured ceiling, in MB.
     *
     * `max_memory_percent` applies to the most specific limit that governs this
     * host: a configured limit, else the container's cgroup limit, else the
     * host. Every other scope still caps the result at what it has free, so a
     * container on an oversubscribed node cannot claim memory the node lacks.
     *
     * @return array{scope: string, limit_mb: float, used_mb: float, headroom_mb: float, capped_by: string|null}
     */
    private function memoryHeadroom(int $maxMemoryPercent): array
    {
        $scopes = $this->cachedMemoryScopes !== []
            ? $this->cachedMemoryScopes
            : ['fallback' => ['limit_mb' => 4096.0, 'used_mb' => 2048.0]];

        $primary = 'fallback';

        foreach (['configured', 'cgroup', 'host', 'fallback'] as $candidate) {
            if (isset($scopes[$candidate])) {
                $primary = $candidate;

                break;
            }
        }

        $headroomMb = $scopes[$primary]['limit_mb'] * ($maxMemoryPercent / 100) - $scopes[$primary]['used_mb'];
        $cappedBy = null;

        foreach ($scopes as $scope => $memory) {
            $freeMb = $memory['limit_mb'] - $memory['used_mb'];

            if ($scope !== $primary && $freeMb < $headroomMb) {
                $headroomMb = $freeMb;
                $cappedBy = $scope;
            }
        }

        return [
            'scope' => $primary,
            'limit_mb' => $scopes[$primary]['limit_mb'],
            'used_mb' => $scopes[$primary]['used_mb'],
            'headroom_mb' => max($headroomMb, 0.0),
            'capped_by' => $cappedBy,
        ];
    }
}
