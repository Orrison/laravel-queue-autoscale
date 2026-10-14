<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\EstimateSource;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\ResourceEstimate;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\WorkerPeakMemory;

/**
 * Resolves per-queue resource estimates using a three-source precedence chain:
 *
 * 1. Measured (from queue-metrics, runtime)  — most trustworthy
 * 2. Per-queue config (queue.resources)      — operator override / cold-start fallback
 * 3. Global default (limits.worker_*_estimate) — last resort
 *
 * Each dimension (CPU, memory) resolves independently. A queue may have
 * measured CPU but fall back to config for memory, or vice versa.
 */
class ResourceEstimateResolver
{
    private const MIN_CPU_CORES = 0.01;

    private const MIN_MEMORY_MB = 16.0;

    /**
     * @var array<string, array{cpu?: float, memory?: float, cpu_samples?: int, memory_samples?: int}>
     */
    private array $measured = [];

    /**
     * What live workers typically peak at, per workload and across the host.
     * See WorkerPeakMemory for the statistic.
     *
     * @var array<string, WorkerPeakMemory>
     */
    private array $workerPeakMemory = [];

    private ?WorkerPeakMemory $hostWorkerPeakMemory = null;

    public function resolve(string $connection, string $queue): ResourceEstimate
    {
        $key = "{$connection}:{$queue}";
        $measuredData = $this->measured[$key] ?? [];
        $configResources = AutoscaleConfiguration::queueResources($queue);

        // Resolve CPU: measured > config > global
        $cpuResult = $this->resolveDimension(
            measured: $measuredData['cpu'] ?? null,
            config: is_numeric($configResources['cpu_cores'] ?? null) ? (float) $configResources['cpu_cores'] : null,
            default: AutoscaleConfiguration::workerCpuCoreEstimate(),
            measuredSamples: $measuredData['cpu_samples'] ?? null,
        );

        // Resolve memory: measured > config > global, then never below what
        // live workers have actually peaked at.
        $memoryResult = $this->resolveDimension(
            measured: $measuredData['memory'] ?? null,
            config: is_numeric($configResources['memory_mb'] ?? null) ? (float) $configResources['memory_mb'] : null,
            default: (float) AutoscaleConfiguration::workerMemoryMbEstimate(),
            measuredSamples: $measuredData['memory_samples'] ?? null,
        );

        $memoryResult = $this->withWorkerPeakFloor($memoryResult, $this->workerPeakMemory[$key] ?? null);

        return new ResourceEstimate(
            cpuCoresPerWorker: max($cpuResult['value'], self::MIN_CPU_CORES),
            memoryMbPerWorker: max($memoryResult['value'], self::MIN_MEMORY_MB),
            cpuSource: $cpuResult['source'],
            memorySource: $memoryResult['source'],
            cpuSampleCount: $cpuResult['samples'],
            memorySampleCount: $memoryResult['samples'],
        );
    }

    public function setMeasured(
        string $connection,
        string $queue,
        float $cpuCoresPerWorker,
        float $memoryMbPerWorker,
        int $cpuSampleCount,
        int $memorySampleCount,
    ): void {
        $key = "{$connection}:{$queue}";
        $this->measured[$key] = [
            'cpu' => $cpuCoresPerWorker,
            'memory' => $memoryMbPerWorker,
            'cpu_samples' => $cpuSampleCount,
            'memory_samples' => $memorySampleCount,
        ];
    }

    public function setMeasuredCpu(string $connection, string $queue, float $cpuCoresPerWorker, int $sampleCount): void
    {
        $key = "{$connection}:{$queue}";
        $this->measured[$key]['cpu'] = $cpuCoresPerWorker;
        $this->measured[$key]['cpu_samples'] = $sampleCount;
    }

    public function setMeasuredMemory(string $connection, string $queue, float $memoryMbPerWorker, int $sampleCount): void
    {
        $key = "{$connection}:{$queue}";
        $this->measured[$key]['memory'] = $memoryMbPerWorker;
        $this->measured[$key]['memory_samples'] = $sampleCount;
    }

    public function reset(): void
    {
        $this->measured = [];
        $this->workerPeakMemory = [];
        $this->hostWorkerPeakMemory = null;
    }

    /**
     * Record the peak memory of every live worker, keyed by workload
     * (`connection:queue`, or `connection:group` for group workers). Replaces
     * the previous observation, so workloads with no live workers drop out.
     *
     * @param  array<string, list<float>>  $peakMbByWorkload
     */
    public function observeWorkerPeakMemory(array $peakMbByWorkload): void
    {
        $this->workerPeakMemory = [];
        $all = [];

        foreach ($peakMbByWorkload as $key => $peaks) {
            if ($peaks === []) {
                continue;
            }

            $this->workerPeakMemory[$key] = WorkerPeakMemory::fromPeaks($peaks);
            array_push($all, ...$peaks);
        }

        $this->hostWorkerPeakMemory = $all === [] ? null : WorkerPeakMemory::fromPeaks($all);
    }

    /**
     * The global default estimate, with memory floored at what this host's
     * workers actually peak at. Used where capacity is sized without a
     * specific workload, such as the per-host capacity a cluster manager
     * reports.
     */
    public function hostEstimate(): ResourceEstimate
    {
        $default = ResourceEstimate::globalDefault();
        $memory = $this->withWorkerPeakFloor(
            ['value' => $default->memoryMbPerWorker, 'source' => $default->memorySource, 'samples' => null],
            $this->hostWorkerPeakMemory,
        );

        return new ResourceEstimate(
            cpuCoresPerWorker: $default->cpuCoresPerWorker,
            memoryMbPerWorker: max($memory['value'], self::MIN_MEMORY_MB),
            cpuSource: $default->cpuSource,
            memorySource: $memory['source'],
            memorySampleCount: $memory['samples'],
        );
    }

    /**
     * @param  array{value: float, source: EstimateSource, samples: int|null}  $memory
     * @return array{value: float, source: EstimateSource, samples: int|null}
     */
    private function withWorkerPeakFloor(array $memory, ?WorkerPeakMemory $peak): array
    {
        if ($peak === null || $peak->mb <= $memory['value']) {
            return $memory;
        }

        return ['value' => $peak->mb, 'source' => EstimateSource::Measured, 'samples' => $peak->workers];
    }

    /**
     * @return array{value: float, source: EstimateSource, samples: int|null}
     */
    private function resolveDimension(?float $measured, ?float $config, float $default, ?int $measuredSamples): array
    {
        if ($measured !== null) {
            return [
                'value' => $measured,
                'source' => EstimateSource::Measured,
                'samples' => $measuredSamples,
            ];
        }

        if ($config !== null) {
            return [
                'value' => $config,
                'source' => EstimateSource::Config,
                'samples' => null,
            ];
        }

        return [
            'value' => $default,
            'source' => EstimateSource::Default,
            'samples' => null,
        ];
    }
}
