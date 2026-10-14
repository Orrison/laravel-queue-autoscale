<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\Calculators;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\CapacityCalculationResult;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\LimitingFactor;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\MemoryHeadroom;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\MemoryScope;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\MemoryScopeKind;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\ResourceEstimate;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\SettlingWorker;
use Cbox\SystemMetrics\DTO\Metrics\Container\ContainerLimits;
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
     * Memory limit and usage in every scope that applies to this host; null
     * until the first successful refresh.
     *
     * @var non-empty-list<MemoryScope>|null
     */
    private ?array $cachedMemoryScopes = null;

    private ?float $cachedAvailableCores = null;

    private ?float $cacheTimestamp = null;

    /**
     * Previous CPU counter snapshot, diffed against the current one to get a
     * usage percentage without sleeping. See measureCpuPercent().
     */
    private ?CpuSnapshot $previousCpuSnapshot = null;

    /** @var (Closure(): list<SettlingWorker>)|null */
    private ?Closure $settlingWorkers = null;

    /**
     * Resident memory of each settling worker, by PID, read alongside the
     * cached usage — the part of their cost that usage already includes.
     *
     * @var array<int, float>
     */
    private array $cachedSettlingResidentMb = [];

    /** @var (Closure(): int)|null */
    private ?Closure $hostWorkerCounter = null;

    private ?OomBackoff $oomBackoff = null;

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
        $totalMemoryMb = $memory->scope->limitMb;
        $usedMemoryMb = $memory->scope->usedMb;
        $currentMemoryPercent = $totalMemoryMb > 0 ? ($usedMemoryMb / $totalMemoryMb) * 100 : 0.0;

        $settling = $this->settlingWorkers($currentWorkers);
        $settlingWorkers = count($settling);
        $headroomMb = max($memory->headroomMb - $this->settlingChargeMb($settling, $workerMemoryMb), 0.0);
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

        $oomCeiling = $this->oomBackoff()->ceiling($this->hostWorkerCount($currentWorkers));

        if ($oomCeiling !== null && $oomCeiling < $finalMaxWorkers) {
            $finalMaxWorkers = $oomCeiling;
            $maxWorkersByMemory = min($maxWorkersByMemory, $oomCeiling);
            $limitingFactor = LimitingFactor::Memory;
        }

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
                $memory->scope->kind->value.($memory->cappedBy !== null ? ", capped by {$memory->cappedBy->value} free memory" : ''),
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
                'memory_scope' => $memory->scope->kind->value,
                'memory_capped_by' => $memory->cappedBy?->value,
                'settling_workers' => $settlingWorkers,
                'worker_memory_mb' => $workerMemoryMb,
                'memory_estimate_source' => $estimate->memorySource->value,
                'oom_backoff_ceiling' => $oomCeiling,
                'oom_backoff_until' => $this->oomBackoff()->holdUntil()?->toIso8601String(),
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
     * Supply the running workers spawned too recently to have reached their
     * memory footprint. The manager owns the pool, so it provides them;
     * without a source none are charged.
     *
     * @param  (Closure(): list<SettlingWorker>)|null  $source
     */
    public function trackSettlingWorkersWith(?Closure $source): void
    {
        $this->settlingWorkers = $source;
    }

    /**
     * Settling workers, read on every call: workers spawned earlier in the
     * same cycle are not in the cached usage yet. Never more than the workers
     * the caller says are running.
     *
     * @return list<SettlingWorker>
     */
    private function settlingWorkers(int $currentWorkers): array
    {
        if ($this->settlingWorkers === null) {
            return [];
        }

        return array_slice(($this->settlingWorkers)(), 0, max($currentWorkers, 0));
    }

    /**
     * Memory still to come from workers that are booting.
     *
     * A worker that has just started has not yet grown to its footprint, so
     * its eventual cost is charged now rather than read as free memory. What
     * it already holds is in the measured usage, so only the rest is charged;
     * charging the full estimate would count that part twice for the whole
     * settle window. A worker spawned after usage was read, or whose resident
     * memory cannot be read, is charged in full.
     *
     * @param  list<SettlingWorker>  $settling
     */
    private function settlingChargeMb(array $settling, float $workerMemoryMb): float
    {
        $chargeMb = 0.0;

        foreach ($settling as $worker) {
            $countedMb = $worker->pid !== null ? ($this->cachedSettlingResidentMb[$worker->pid] ?? 0.0) : 0.0;
            $chargeMb += max($workerMemoryMb - $countedMb, 0.0);
        }

        return $chargeMb;
    }

    /**
     * @return array<int, float>
     */
    private function measureSettlingResidentMb(): array
    {
        $residentMb = [];

        foreach ($this->settlingWorkers(PHP_INT_MAX) as $worker) {
            if ($worker->pid !== null && $worker->residentMb !== null) {
                $residentMb[$worker->pid] = $worker->residentMb;
            }
        }

        return $residentMb;
    }

    /**
     * Supply how many workers are running on this host in total. The manager
     * owns the pool, so it provides the count.
     *
     * The OOM backoff fixes its ceiling at the worker count the first time it
     * is asked after a kill, and that must be the host's total. Callers pass
     * different counts as `$currentWorkers` — the scaling engine and cluster
     * heartbeat the host total, NoScaleDownPolicy a single queue's — so
     * whichever ran first would otherwise decide the ceiling, and a per-queue
     * count would clamp the whole host down to one queue's workers.
     *
     * @param  (Closure(): int)|null  $counter
     */
    public function countHostWorkersWith(?Closure $counter): void
    {
        $this->hostWorkerCounter = $counter;
    }

    /**
     * The host's total worker count, or the caller's count when no counter is
     * supplied (a calculator used outside the manager).
     */
    private function hostWorkerCount(int $currentWorkers): int
    {
        if ($this->hostWorkerCounter === null) {
            return $currentWorkers;
        }

        return max(($this->hostWorkerCounter)(), $currentWorkers);
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
            $this->cachedCpuPercent = null;
            $this->cacheTimestamp = null;

            return;
        }

        $limits = $limitsResult->getValue();
        $this->cachedAvailableCores = $limits->availableCpuCores();

        $this->cachedCpuPercent = $this->measureCpuPercent();

        $containerResult = SystemMetrics::container();
        $container = $containerResult->isSuccess() ? $containerResult->getValue() : null;

        $this->cachedMemoryScopes = $this->measureMemoryScopes($limits, $container);
        $this->cachedSettlingResidentMb = $this->measureSettlingResidentMb();

        $this->oomBackoff()->observe($container?->oomKillCount);

        $this->cacheTimestamp = microtime(true);
    }

    /**
     * Memory limit and usage in every scope that can stop this host's workers.
     *
     * - host: the kernel's MemTotal and MemAvailable, which already discounts
     *   reclaimable page cache. Always applies.
     * - cgroup: the container's own limit and usage, only when a limit is set.
     *   A cgroup without one says nothing about how much memory there is.
     *   Usage is the working set (usage minus inactive file cache) where the
     *   kernel reports it: raw cgroup usage counts page cache the kernel would
     *   reclaim, so a container writing log files would read as nearly full
     *   and stop scaling up. The host scope already discounts it the same way.
     * - configured: `limits.memory_limit_mb`, for a limit the process cannot
     *   see, such as one set only on a parent cgroup (a pod or task limit).
     *
     * Each scope pairs a limit with usage measured against that same limit.
     *
     * @return non-empty-list<MemoryScope>
     */
    private function measureMemoryScopes(SystemLimits $limits, ?ContainerLimits $container): array
    {
        $scopes = [];
        $hostUsedMb = null;
        $cgroupUsedMb = null;

        $memoryResult = SystemMetrics::memory();

        if ($memoryResult->isSuccess()) {
            $memory = $memoryResult->getValue();

            if ($memory->totalBytes > 0) {
                $hostUsedMb = self::toMb(max($memory->totalBytes - $memory->availableBytes, 0));
                $scopes[] = new MemoryScope(MemoryScopeKind::Host, self::toMb($memory->totalBytes), $hostUsedMb);
            }
        }

        $cgroupUsedBytes = $container->memoryWorkingSetBytes ?? $container?->memoryUsageBytes;

        if ($container !== null && $cgroupUsedBytes !== null) {
            $cgroupUsedMb = self::toMb($cgroupUsedBytes);
            $cgroupLimitBytes = $container->memoryLimitBytes;

            // Zero means no limit was set, the same as hasMemoryLimit().
            if ($cgroupLimitBytes !== null && $cgroupLimitBytes > 0) {
                $scopes[] = new MemoryScope(MemoryScopeKind::Cgroup, self::toMb($cgroupLimitBytes), $cgroupUsedMb);
            }
        }

        $configuredLimitMb = AutoscaleConfiguration::memoryLimitMb();
        $configuredUsedMb = $cgroupUsedMb ?? $hostUsedMb;

        if ($configuredLimitMb !== null && $configuredUsedMb !== null) {
            $scopes[] = new MemoryScope(MemoryScopeKind::Configured, $configuredLimitMb, $configuredUsedMb);
        }

        if ($scopes === []) {
            return [self::fallbackMemoryScope($limits->memoryBytes)];
        }

        return $scopes;
    }

    /**
     * Nothing readable: keep the long-standing assumption of half the
     * reported memory in use.
     */
    private static function fallbackMemoryScope(int $reportedBytes): MemoryScope
    {
        $totalMb = $reportedBytes > 0 ? self::toMb($reportedBytes) : 4096.0;

        return new MemoryScope(MemoryScopeKind::Fallback, $totalMb, $totalMb * 0.5);
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
     */
    private function memoryHeadroom(int $maxMemoryPercent): MemoryHeadroom
    {
        $scopes = $this->cachedMemoryScopes ?? [self::fallbackMemoryScope(0)];
        $primary = $scopes[0];

        foreach (MemoryScopeKind::byPrecedence() as $kind) {
            $match = array_find($scopes, fn (MemoryScope $scope): bool => $scope->kind === $kind);

            if ($match !== null) {
                $primary = $match;

                break;
            }
        }

        $headroomMb = $primary->limitMb * ($maxMemoryPercent / 100) - $primary->usedMb;
        $cappedBy = null;

        foreach ($scopes as $scope) {
            if ($scope !== $primary && $scope->freeMb() < $headroomMb) {
                $headroomMb = $scope->freeMb();
                $cappedBy = $scope->kind;
            }
        }

        return new MemoryHeadroom($primary, max($headroomMb, 0.0), $cappedBy);
    }

    private function oomBackoff(): OomBackoff
    {
        return $this->oomBackoff ??= new OomBackoff;
    }
}
