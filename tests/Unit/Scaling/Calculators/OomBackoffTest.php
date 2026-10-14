<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Policies\NoScaleDownPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\OomBackoff;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\LimitingFactor;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\ResourceEstimate;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;
use Cbox\SystemMetrics\DTO\Metrics\Container\CgroupVersion;
use Cbox\SystemMetrics\DTO\Metrics\Container\ContainerLimits;
use Cbox\SystemMetrics\DTO\Metrics\LimitSource;
use Cbox\SystemMetrics\DTO\Metrics\SystemLimits;
use Cbox\SystemMetrics\Testing\FakeSystemMetrics;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    $this->fakes = FakeSystemMetrics::install();

    // Plenty of CPU and memory, so only the backoff can hold capacity down.
    $this->fakes->limits->set(new SystemLimits(
        source: LimitSource::CGROUP_V2,
        cpuCores: 64.0,
        memoryBytes: 64 * 1024 ** 3,
        currentCpuCores: 0.0,
        currentMemoryBytes: 0.0,
    ));

    config()->set('queue-autoscale.limits.oom_backoff_seconds', 300);
    config()->set('queue-autoscale.limits.reserve_cpu_cores', 0);

    $this->calculator = new CapacityCalculator;

    $this->warnings = 0;
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning')->andReturnUsing(function (): void {
        $this->warnings++;
    });
});

afterEach(function (): void {
    FakeSystemMetrics::uninstall();
});

function oomKillsSoFar(FakeSystemMetrics $fakes, ?int $count): void
{
    $fakes->container->set(new ContainerLimits(
        cgroupVersion: CgroupVersion::V2,
        cpuQuota: null,
        memoryLimitBytes: null,
        cpuUsageCores: null,
        memoryUsageBytes: null,
        cpuThrottledCount: null,
        oomKillCount: $count,
    ));
}

function oomCapacity(CapacityCalculator $calculator, int $currentWorkers): int
{
    $calculator->invalidateCache();

    return $calculator->calculateMaxWorkers(
        $currentWorkers,
        ResourceEstimate::fromConfig(cpuCoresPerWorker: 0.01, memoryMbPerWorker: 16.0),
    )->finalMaxWorkers;
}

test('holds capacity at the current worker count after the OOM kill counter rises', function (): void {
    oomKillsSoFar($this->fakes, 2);
    $unconstrained = oomCapacity($this->calculator, 40);

    oomKillsSoFar($this->fakes, 5);
    $this->calculator->invalidateCache();
    $result = $this->calculator->calculateMaxWorkers(
        36,
        ResourceEstimate::fromConfig(cpuCoresPerWorker: 0.01, memoryMbPerWorker: 16.0),
    );

    expect($unconstrained)->toBeGreaterThan(40)
        ->and($result->finalMaxWorkers)->toBe(36)
        ->and($result->limitingFactor)->toBe(LimitingFactor::Memory)
        ->and($result->details['memory_details']['oom_backoff_ceiling'])->toBe(36);

    expect($this->warnings)->toBe(1);
});

test('keeps the ceiling fixed for the hold, then releases it', function (): void {
    oomKillsSoFar($this->fakes, 0);
    oomCapacity($this->calculator, 40);

    oomKillsSoFar($this->fakes, 1);
    expect(oomCapacity($this->calculator, 36))->toBe(36);

    // Spawning more during the hold must not raise the ceiling with it.
    $this->travel(200)->seconds();
    expect(oomCapacity($this->calculator, 38))->toBe(36);

    $this->travel(101)->seconds();
    expect(oomCapacity($this->calculator, 36))->toBeGreaterThan(36);
});

test('does not hold capacity on the first reading or an unchanged counter', function (): void {
    oomKillsSoFar($this->fakes, 9);

    expect(oomCapacity($this->calculator, 40))->toBeGreaterThan(40)
        ->and(oomCapacity($this->calculator, 40))->toBeGreaterThan(40);
});

test('does nothing where the counter is not available', function (): void {
    oomKillsSoFar($this->fakes, null);
    oomCapacity($this->calculator, 40);
    oomKillsSoFar($this->fakes, null);

    expect(oomCapacity($this->calculator, 40))->toBeGreaterThan(40);
});

test('can be disabled', function (): void {
    config()->set('queue-autoscale.limits.oom_backoff_seconds', 0);

    oomKillsSoFar($this->fakes, 0);
    oomCapacity($this->calculator, 40);
    oomKillsSoFar($this->fakes, 3);

    expect(oomCapacity($this->calculator, 36))->toBeGreaterThan(36);
});

test('fixes the ceiling at the host total even when a per-queue caller asks first', function (): void {
    $hostWorkers = 40;
    $this->calculator->countHostWorkersWith(function () use (&$hostWorkers): int {
        return $hostWorkers;
    });

    oomKillsSoFar($this->fakes, 0);
    oomCapacity($this->calculator, 40);

    oomKillsSoFar($this->fakes, 1);
    $hostWorkers = 36;
    $this->calculator->invalidateCache();

    // NoScaleDownPolicy passes one queue's count (here 4); the ceiling must
    // still be the host's 36, or every other queue is clamped to nothing.
    $policy = new NoScaleDownPolicy($this->calculator);
    $policy->beforeScaling(new ScalingDecision(
        connection: 'redis',
        queue: 'emails',
        currentWorkers: 4,
        targetWorkers: 2,
        reason: 'idle',
        predictedPickupTime: null,
        slaTarget: 30,
    ));

    expect(oomCapacity($this->calculator, 36))->toBe(36);
});

test('a second kill during a hold extends it and re-fixes the ceiling at the new count', function (): void {
    $this->freezeTime();
    $backoff = new OomBackoff;

    $backoff->observe(0);
    $backoff->observe(1);
    expect($backoff->ceiling(36))->toBe(36);

    $this->travel(200)->seconds();
    $backoff->observe(2);

    // The ceiling follows the count after the second kill, and the hold runs
    // a full window from it rather than ending at the first kill's deadline.
    expect($backoff->ceiling(30))->toBe(30)
        ->and($backoff->holdUntil()?->equalTo(now()->addSeconds(300)))->toBeTrue();

    $this->travel(150)->seconds();
    expect($backoff->ceiling(40))->toBe(30);

    expect($this->warnings)->toBe(2);
});

test('a counter that resets is re-baselined, and a rise after it holds again', function (): void {
    $backoff = new OomBackoff;

    $backoff->observe(7);
    $backoff->observe(0);

    expect($backoff->ceiling(20))->toBeNull()
        ->and($backoff->holdUntil())->toBeNull();

    $backoff->observe(1);

    expect($backoff->ceiling(20))->toBe(20)
        ->and($this->warnings)->toBe(1);
});

test('a reading the counter cannot provide keeps the previous baseline', function (): void {
    $backoff = new OomBackoff;

    $backoff->observe(3);
    $backoff->observe(null);
    $backoff->observe(4);

    expect($backoff->ceiling(10))->toBe(10);
});

test('the hold ends exactly at its deadline and forgets the ceiling', function (): void {
    $this->freezeTime();
    $backoff = new OomBackoff;

    $backoff->observe(0);
    $backoff->observe(1);
    expect($backoff->ceiling(12))->toBe(12);

    $this->travel(299)->seconds();
    expect($backoff->ceiling(15))->toBe(12);

    $this->travel(1)->seconds();
    expect($backoff->ceiling(15))->toBeNull()
        ->and($backoff->holdUntil())->toBeNull();
});

test('a disabled backoff neither holds nor logs a kill', function (int $seconds): void {
    config()->set('queue-autoscale.limits.oom_backoff_seconds', $seconds);
    $backoff = new OomBackoff;

    $backoff->observe(0);
    $backoff->observe(5);

    expect($backoff->ceiling(10))->toBeNull()
        ->and($backoff->holdUntil())->toBeNull()
        ->and($this->warnings)->toBe(0);
})->with(['zero' => [0], 'negative' => [-60]]);

test('the ceiling is never negative', function (): void {
    $backoff = new OomBackoff;

    $backoff->observe(0);
    $backoff->observe(1);

    expect($backoff->ceiling(-3))->toBe(0);
});
