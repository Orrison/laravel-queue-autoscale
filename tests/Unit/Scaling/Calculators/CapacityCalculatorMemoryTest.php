<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\CapacityCalculationResult;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\MemoryScopeKind;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\ResourceEstimate;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\SettlingWorker;
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

function cgroupMemory(?int $limitMb, int $usageMb, ?int $workingSetMb = null): ContainerLimits
{
    return new ContainerLimits(
        cgroupVersion: CgroupVersion::V2,
        cpuQuota: null,
        memoryLimitBytes: $limitMb === null ? null : $limitMb * CAPACITY_TEST_MB,
        cpuUsageCores: null,
        memoryUsageBytes: $usageMb * CAPACITY_TEST_MB,
        cpuThrottledCount: null,
        oomKillCount: 0,
        memoryWorkingSetBytes: $workingSetMb === null ? null : $workingSetMb * CAPACITY_TEST_MB,
    );
}

/**
 * @return list<SettlingWorker>
 */
function settlingWorkers(int $count): array
{
    return array_fill(0, $count, new SettlingWorker);
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

test('reclaimable page cache in a cgroup does not count as used', function (): void {
    // 3800 MB raw usage, of which 1800 MB is inactive file cache.
    $this->fakes->memory->set(hostMemory(totalMb: 16_000, availableMb: 12_000));
    $this->fakes->container->set(cgroupMemory(limitMb: 4_000, usageMb: 3_800, workingSetMb: 2_000));
    config()->set('queue-autoscale.limits.max_memory_percent', 85);

    $result = memoryCapacity();

    // Same as 2000 MB used: 85% of 4000 MB minus the working set.
    expect($result->maxWorkersByMemory)->toBe(14)
        ->and($result->details['memory_details']['used_memory_mb'])->toBe(2_000.0);
});

test('without a working set, raw cgroup usage is used', function (): void {
    $this->fakes->memory->set(hostMemory(totalMb: 16_000, availableMb: 12_000));
    $this->fakes->container->set(cgroupMemory(limitMb: 4_000, usageMb: 3_000));
    config()->set('queue-autoscale.limits.max_memory_percent', 85);

    expect(memoryCapacity()->details['memory_details']['used_memory_mb'])->toBe(3_000.0);
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
    $calculator->trackSettlingWorkersWith(fn (): array => settlingWorkers(8));

    $result = memoryCapacity(currentWorkers: 10, calculator: $calculator);

    // 3200 MB headroom less 8 x 100 MB not yet in use.
    expect($result->maxWorkersByMemory)->toBe(10 + 24)
        ->and($result->details['memory_details']['settling_workers'])->toBe(8);
});

test('the settling count is re-read on every call within a cycle', function (): void {
    $this->fakes->memory->set(hostMemory(totalMb: 16_000, availableMb: 8_000));

    $settling = 0;
    $calculator = new CapacityCalculator;
    $calculator->trackSettlingWorkersWith(function () use (&$settling): array {
        return settlingWorkers($settling);
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
    $calculator->trackSettlingWorkersWith(fn (): array => settlingWorkers(2));

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

test('a settling worker is charged only the memory it does not hold yet', function (): void {
    $this->fakes->memory->set(hostMemory(totalMb: 16_000, availableMb: 8_000));

    $settling = [new SettlingWorker(pid: 101, residentMb: 40.0), new SettlingWorker(pid: 102, residentMb: 250.0)];
    $calculator = new CapacityCalculator;
    $calculator->trackSettlingWorkersWith(function () use (&$settling): array {
        return $settling;
    });

    $before = memoryCapacity(currentWorkers: 2, calculator: $calculator);

    // 3200 MB headroom less 60 MB still to come from the first worker; the
    // second already holds more than the estimate and costs nothing further.
    expect($before->details['memory_details']['headroom_mb'])->toBe(3_140.0)
        ->and($before->details['memory_details']['settling_workers'])->toBe(2);

    // Spawned after usage was read, so none of its memory is counted yet,
    // whatever it holds by now.
    $settling[] = new SettlingWorker(pid: 103, residentMb: 90.0);
    $after = memoryCapacity(currentWorkers: 3, calculator: $calculator);

    expect($after->details['memory_details']['headroom_mb'])->toBe(3_040.0);
});

test('a settling worker whose resident memory cannot be read is charged in full', function (): void {
    $this->fakes->memory->set(hostMemory(totalMb: 16_000, availableMb: 8_000));

    $calculator = new CapacityCalculator;
    $calculator->trackSettlingWorkersWith(fn (): array => [new SettlingWorker(pid: 101)]);

    expect(memoryCapacity(currentWorkers: 1, calculator: $calculator)->details['memory_details']['headroom_mb'])->toBe(3_100.0);
});

test('with all three scopes present the configured limit governs and the tightest other scope caps it', function (int $cgroupLimitMb, int $hostAvailableMb, ?string $cappedBy, float $headroomMb): void {
    // Container uses 3000 MB. Configured 8000 MB at 70% leaves 2600 MB.
    $this->fakes->memory->set(hostMemory(totalMb: 16_000, availableMb: $hostAvailableMb));
    $this->fakes->container->set(cgroupMemory(limitMb: $cgroupLimitMb, usageMb: 3_000));
    config()->set('queue-autoscale.limits.memory_limit_mb', 8_000);

    $result = memoryCapacity();
    $memory = $result->details['memory_details'];

    expect($memory['memory_scope'])->toBe('configured')
        ->and($memory['memory_capped_by'])->toBe($cappedBy)
        ->and($memory['headroom_mb'])->toBe($headroomMb)
        ->and($result->maxWorkersByMemory)->toBe((int) floor($headroomMb / 100));
})->with([
    'neither cgroup nor host is tighter' => [6_000, 10_000, null, 2_600.0],
    'the cgroup has less free than the configured headroom' => [5_000, 10_000, 'cgroup', 2_000.0],
    'the host has the least free of all' => [5_000, 1_500, 'host', 1_500.0],
]);

test('total and current percent describe the scope that governs, not the host', function (): void {
    $this->fakes->memory->set(hostMemory(totalMb: 16_000, availableMb: 10_000));
    $this->fakes->container->set(cgroupMemory(limitMb: 6_000, usageMb: 3_000));
    config()->set('queue-autoscale.limits.memory_limit_mb', 8_000);

    $memory = memoryCapacity()->details['memory_details'];

    expect($memory['total_memory_mb'])->toBe(8_000.0)
        ->and($memory['used_memory_mb'])->toBe(3_000.0)
        ->and($memory['current_memory_percent'])->toBe(37.5)
        // 2600 MB of headroom out of 8000 MB.
        ->and($memory['available_memory_percent'])->toBe(32.5);
});

test('a configured limit with no readable container is measured against host usage', function (): void {
    $this->fakes->memory->set(hostMemory(totalMb: 16_000, availableMb: 13_000));
    $this->fakes->container->failWith(new SystemMetricsException('not a container'));
    config()->set('queue-autoscale.limits.memory_limit_mb', 6_000);

    $memory = memoryCapacity()->details['memory_details'];

    // 70% of 6000 MB minus the host's 3000 MB in use.
    expect($memory['memory_scope'])->toBe('configured')
        ->and($memory['used_memory_mb'])->toBe(3_000.0)
        ->and($memory['headroom_mb'])->toBe(1_200.0);
});

test('a configured limit that is not a positive number is ignored', function (mixed $configured): void {
    $this->fakes->memory->set(hostMemory(totalMb: 16_000, availableMb: 8_000));
    config()->set('queue-autoscale.limits.memory_limit_mb', $configured);

    expect(memoryCapacity()->details['memory_details']['memory_scope'])->toBe('host');
})->with([
    'zero' => [0],
    'negative' => [-512],
    'not a number' => ['unlimited'],
    'empty env value' => [''],
]);

test('a configured limit given as an env string is honoured', function (): void {
    $this->fakes->memory->set(hostMemory(totalMb: 16_000, availableMb: 15_000));
    config()->set('queue-autoscale.limits.memory_limit_mb', '2048');

    $memory = memoryCapacity()->details['memory_details'];

    expect($memory['memory_scope'])->toBe('configured')
        ->and($memory['total_memory_mb'])->toBe(2_048.0);
});

test('usage already above the ceiling leaves no headroom and no new workers', function (): void {
    // 90% used under a 70% ceiling.
    $this->fakes->memory->set(hostMemory(totalMb: 10_000, availableMb: 1_000));

    $result = memoryCapacity(currentWorkers: 12);
    $memory = $result->details['memory_details'];

    expect($memory['headroom_mb'])->toBe(0.0)
        ->and($memory['available_memory_percent'])->toBe(0.0)
        ->and($result->maxWorkersByMemory)->toBe(12);
});

test('never charges more settling workers than the caller says are running', function (): void {
    $this->fakes->memory->set(hostMemory(totalMb: 16_000, availableMb: 8_000));

    $calculator = new CapacityCalculator;
    $calculator->trackSettlingWorkersWith(fn (): array => settlingWorkers(8));

    $memory = memoryCapacity(currentWorkers: 3, calculator: $calculator)->details['memory_details'];

    // 3200 MB headroom less 3 x 100 MB, not 8 x 100 MB.
    expect($memory['settling_workers'])->toBe(3)
        ->and($memory['headroom_mb'])->toBe(2_900.0);
});

test('the settling charge cannot push headroom below zero', function (): void {
    $this->fakes->memory->set(hostMemory(totalMb: 16_000, availableMb: 8_000));

    $calculator = new CapacityCalculator;
    $calculator->trackSettlingWorkersWith(fn (): array => settlingWorkers(40));

    $result = memoryCapacity(currentWorkers: 40, calculator: $calculator);

    expect($result->details['memory_details']['headroom_mb'])->toBe(0.0)
        ->and($result->maxWorkersByMemory)->toBe(40);
});

test('the memory explanation names the governing scope and the scope that capped it', function (): void {
    $this->fakes->memory->set(hostMemory(totalMb: 64_000, availableMb: 500));
    $this->fakes->container->set(cgroupMemory(limitMb: 4_000, usageMb: 1_000));

    expect(memoryCapacity()->details['memory_explanation'])
        ->toBe('0.5GB available of 3.9GB (cgroup, capped by host free memory), 100MB/worker');
});

test('a working set of zero is used rather than falling back to raw usage', function (): void {
    // Zero is a reading, not a missing value: only null falls back.
    $this->fakes->memory->set(hostMemory(totalMb: 16_000, availableMb: 12_000));
    $this->fakes->container->set(cgroupMemory(limitMb: 4_000, usageMb: 1_500, workingSetMb: 0));

    expect(memoryCapacity()->details['memory_details']['used_memory_mb'])->toBe(0.0);
});

test('an active OOM hold reports its ceiling and when it ends', function (): void {
    $this->freezeTime();
    $this->fakes->memory->set(hostMemory(totalMb: 16_000, availableMb: 8_000));
    config()->set('queue-autoscale.limits.oom_backoff_seconds', 120);

    $calculator = new CapacityCalculator;
    $this->fakes->container->set(new ContainerLimits(
        cgroupVersion: CgroupVersion::V2,
        cpuQuota: null,
        memoryLimitBytes: null,
        cpuUsageCores: null,
        memoryUsageBytes: null,
        cpuThrottledCount: null,
        oomKillCount: 0,
    ));
    memoryCapacity(currentWorkers: 5, calculator: $calculator);

    $this->fakes->container->set(new ContainerLimits(
        cgroupVersion: CgroupVersion::V2,
        cpuQuota: null,
        memoryLimitBytes: null,
        cpuUsageCores: null,
        memoryUsageBytes: null,
        cpuThrottledCount: null,
        oomKillCount: 1,
    ));
    $calculator->invalidateCache();
    $result = memoryCapacity(currentWorkers: 5, calculator: $calculator);
    $memory = $result->memoryBreakdown();

    expect($result->finalMaxWorkers)->toBe(5)
        ->and($result->maxWorkersByMemory)->toBe(5)
        ->and($memory->oomBackoffCeiling)->toBe(5)
        ->and($memory->oomBackoffUntil)->toBe(now()->addSeconds(120)->toIso8601String());
});
