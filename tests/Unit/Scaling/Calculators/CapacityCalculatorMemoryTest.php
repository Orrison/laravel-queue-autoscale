<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\CapacityCalculationResult;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\MemoryScopeKind;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\ResourceEstimate;
use Cbox\SystemMetrics\DTO\Metrics\Container\CgroupVersion;
use Cbox\SystemMetrics\DTO\Metrics\Container\ContainerLimits;
use Cbox\SystemMetrics\DTO\Metrics\LimitSource;
use Cbox\SystemMetrics\DTO\Metrics\Memory\MemorySnapshot;
use Cbox\SystemMetrics\DTO\Metrics\SystemLimits;
use Cbox\SystemMetrics\Exceptions\SystemMetricsException;
use Cbox\SystemMetrics\Testing\FakeSystemMetrics;

const CAPACITY_TEST_MB = 1024 * 1024;

beforeEach(function (): void {
    $this->fakes = FakeSystemMetrics::install();

    // Plenty of CPU, so every result here is limited by memory.
    $this->fakes->limits->set(new SystemLimits(
        source: LimitSource::HOST,
        cpuCores: 64.0,
        memoryBytes: 16_000 * CAPACITY_TEST_MB,
        currentCpuCores: 0.0,
        currentMemoryBytes: 0.0,
    ));

    config()->set('queue-autoscale.limits.max_memory_percent', 70);
    config()->set('queue-autoscale.limits.reserve_cpu_cores', 0);
});

afterEach(function (): void {
    FakeSystemMetrics::uninstall();
});

function hostMemory(int $totalMb, int $availableMb): MemorySnapshot
{
    return new MemorySnapshot(
        totalBytes: $totalMb * CAPACITY_TEST_MB,
        freeBytes: $availableMb * CAPACITY_TEST_MB,
        availableBytes: $availableMb * CAPACITY_TEST_MB,
        usedBytes: ($totalMb - $availableMb) * CAPACITY_TEST_MB,
        buffersBytes: 0,
        cachedBytes: 0,
        swapTotalBytes: 0,
        swapFreeBytes: 0,
        swapUsedBytes: 0,
    );
}

function cgroupMemory(?int $limitMb, int $usageMb): ContainerLimits
{
    return new ContainerLimits(
        cgroupVersion: CgroupVersion::V2,
        cpuQuota: null,
        memoryLimitBytes: $limitMb === null ? null : $limitMb * CAPACITY_TEST_MB,
        cpuUsageCores: null,
        memoryUsageBytes: $usageMb * CAPACITY_TEST_MB,
        cpuThrottledCount: null,
        oomKillCount: 0,
    );
}

function memoryCapacity(int $currentWorkers = 0, ?CapacityCalculator $calculator = null): CapacityCalculationResult
{
    return ($calculator ?? new CapacityCalculator)->calculateMaxWorkers(
        $currentWorkers,
        ResourceEstimate::fromConfig(cpuCoresPerWorker: 0.01, memoryMbPerWorker: 100.0),
    );
}

test('on a host, headroom is the ceiling minus usage, counted once', function (): void {
    // 50% used under a 70% ceiling leaves 20% of the host: 3200 MB.
    $this->fakes->memory->set(hostMemory(totalMb: 16_000, availableMb: 8_000));

    $result = memoryCapacity();
    $memory = $result->details['memory_details'];

    expect($result->maxWorkersByMemory)->toBe(32)
        ->and($memory['memory_scope'])->toBe('host')
        ->and($memory['current_memory_percent'])->toBe(50.0)
        ->and($memory['available_memory_percent'])->toBe(20.0);
});

test('in a container with its own limit, headroom follows the cgroup', function (): void {
    $this->fakes->memory->set(hostMemory(totalMb: 16_000, availableMb: 12_000));
    $this->fakes->container->set(cgroupMemory(limitMb: 4_000, usageMb: 2_000));
    config()->set('queue-autoscale.limits.max_memory_percent', 85);

    $result = memoryCapacity();

    // 85% of 4000 MB minus 2000 MB used.
    expect($result->maxWorkersByMemory)->toBe(14)
        ->and($result->details['memory_details']['memory_scope'])->toBe('cgroup')
        ->and($result->details['memory_details']['total_memory_mb'])->toBe(4_000.0);
});

test('a cgroup without a memory limit does not borrow the host total', function (): void {
    // The limit is on a parent cgroup the process cannot see; the cgroup's own
    // usage must not be paired with the host's total.
    $this->fakes->memory->set(hostMemory(totalMb: 16_000, availableMb: 15_000));
    $this->fakes->container->set(cgroupMemory(limitMb: null, usageMb: 700));

    $memory = memoryCapacity()->details['memory_details'];

    expect($memory['memory_scope'])->toBe('host')
        ->and($memory['used_memory_mb'])->toBe(1_000.0);
});

test('a configured limit wins and is measured against the container usage', function (): void {
    $this->fakes->memory->set(hostMemory(totalMb: 16_000, availableMb: 15_000));
    $this->fakes->container->set(cgroupMemory(limitMb: null, usageMb: 4_000));
    config()->set('queue-autoscale.limits.memory_limit_mb', 8_000);

    $result = memoryCapacity();

    // 70% of 8000 MB minus 4000 MB used.
    expect($result->maxWorkersByMemory)->toBe(16)
        ->and($result->details['memory_details']['memory_scope'])->toBe('configured');
});

test('a container on a crowded host is capped by what the host has free', function (): void {
    $this->fakes->memory->set(hostMemory(totalMb: 64_000, availableMb: 500));
    $this->fakes->container->set(cgroupMemory(limitMb: 4_000, usageMb: 1_000));

    $result = memoryCapacity();

    expect($result->maxWorkersByMemory)->toBe(5)
        ->and($result->details['memory_details']['memory_scope'])->toBe('cgroup')
        ->and($result->details['memory_details']['memory_capped_by'])->toBe('host');
});

test('a node with little free memory does not limit a container that fits its own limit', function (): void {
    // MemAvailable already excludes reclaimable cache, so a busy node with
    // free memory left does not shrink the container's own budget.
    $this->fakes->memory->set(hostMemory(totalMb: 64_000, availableMb: 6_000));
    $this->fakes->container->set(cgroupMemory(limitMb: 4_000, usageMb: 1_000));

    expect(memoryCapacity()->maxWorkersByMemory)->toBe(18);
});

test('workers still settling are charged their full estimate', function (): void {
    $this->fakes->memory->set(hostMemory(totalMb: 16_000, availableMb: 8_000));

    $calculator = new CapacityCalculator;
    $calculator->countSettlingWorkersWith(fn (): int => 8);

    $result = memoryCapacity(currentWorkers: 10, calculator: $calculator);

    // 3200 MB headroom less 8 x 100 MB not yet in use.
    expect($result->maxWorkersByMemory)->toBe(10 + 24)
        ->and($result->details['memory_details']['settling_workers'])->toBe(8);
});

test('the settling count is re-read on every call within a cycle', function (): void {
    $this->fakes->memory->set(hostMemory(totalMb: 16_000, availableMb: 8_000));

    $settling = 0;
    $calculator = new CapacityCalculator;
    $calculator->countSettlingWorkersWith(function () use (&$settling): int {
        return $settling;
    });

    $before = memoryCapacity(currentWorkers: 0, calculator: $calculator);
    $settling = 8;
    $after = memoryCapacity(currentWorkers: 8, calculator: $calculator);

    // Spawning 8 must not raise the ceiling while their memory is not yet used.
    expect($before->maxWorkersByMemory)->toBe(32)
        ->and($after->maxWorkersByMemory)->toBe(32);
});

test('falls back to half of the reported memory when nothing can be read', function (): void {
    $this->fakes->memory->failWith(new SystemMetricsException('unavailable'));

    $memory = memoryCapacity()->details['memory_details'];

    expect($memory['memory_scope'])->toBe('fallback')
        ->and($memory['total_memory_mb'])->toBe(16_000.0)
        ->and($memory['current_memory_percent'])->toBe(50.0);
});

test('the typed memory breakdown carries the scope, cap and settling charge', function (): void {
    $this->fakes->memory->set(hostMemory(totalMb: 64_000, availableMb: 500));
    $this->fakes->container->set(cgroupMemory(limitMb: 4_000, usageMb: 1_000));

    $calculator = new CapacityCalculator;
    $calculator->countSettlingWorkersWith(fn (): int => 2);

    $memory = memoryCapacity(currentWorkers: 2, calculator: $calculator)->memoryBreakdown();

    expect($memory->scope)->toBe(MemoryScopeKind::Cgroup)
        ->and($memory->cappedBy)->toBe(MemoryScopeKind::Host)
        ->and($memory->usedMemoryMb)->toBe(1_000.0)
        ->and($memory->headroomMb)->toBe(300.0)
        ->and($memory->settlingWorkers)->toBe(2)
        ->and($memory->oomBackoffCeiling)->toBeNull()
        ->and($memory->oomBackoffUntil)->toBeNull();
});

test('a cgroup reporting a zero memory limit is treated as having none', function (): void {
    $this->fakes->memory->set(hostMemory(totalMb: 16_000, availableMb: 8_000));
    $this->fakes->container->set(cgroupMemory(limitMb: 0, usageMb: 700));

    expect(memoryCapacity()->details['memory_details']['memory_scope'])->toBe('host');
});
