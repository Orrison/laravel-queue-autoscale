<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\DTOs\CapacityCalculationResult;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\LimitingFactor;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\MemoryBreakdown;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\MemoryScopeKind;

test('reads every documented memory_details key into its typed property', function (): void {
    $memory = MemoryBreakdown::fromDetails([
        'max_memory_percent' => 85,
        'current_memory_percent' => 50.0,
        'available_memory_percent' => 25.0,
        'total_memory_mb' => 4_000.0,
        'used_memory_mb' => 2_000.0,
        'headroom_mb' => 1_000.0,
        'memory_scope' => 'configured',
        'memory_capped_by' => 'host',
        'settling_workers' => 3,
        'worker_memory_mb' => 128.0,
        'memory_estimate_source' => 'measured',
        'oom_backoff_ceiling' => 12,
        'oom_backoff_until' => '2026-10-14T12:05:00+00:00',
    ]);

    expect($memory->maxMemoryPercent)->toBe(85.0)
        ->and($memory->currentMemoryPercent)->toBe(50.0)
        ->and($memory->availableMemoryPercent)->toBe(25.0)
        ->and($memory->totalMemoryMb)->toBe(4_000.0)
        ->and($memory->usedMemoryMb)->toBe(2_000.0)
        ->and($memory->headroomMb)->toBe(1_000.0)
        ->and($memory->scope)->toBe(MemoryScopeKind::Configured)
        ->and($memory->cappedBy)->toBe(MemoryScopeKind::Host)
        ->and($memory->settlingWorkers)->toBe(3)
        ->and($memory->workerMemoryMb)->toBe(128.0)
        ->and($memory->estimateSource)->toBe('measured')
        ->and($memory->oomBackoffCeiling)->toBe(12)
        ->and($memory->oomBackoffUntil)->toBe('2026-10-14T12:05:00+00:00');
});

test('details written before v4.4 still parse, with the new fields at their defaults', function (): void {
    $memory = MemoryBreakdown::fromDetails([
        'max_memory_percent' => 85,
        'current_memory_percent' => 40.0,
        'available_memory_percent' => 45.0,
        'total_memory_mb' => 8_000.0,
        'worker_memory_mb' => 128.0,
        'memory_estimate_source' => 'default',
    ]);

    expect($memory->totalMemoryMb)->toBe(8_000.0)
        ->and($memory->usedMemoryMb)->toBe(0.0)
        ->and($memory->headroomMb)->toBe(0.0)
        ->and($memory->scope)->toBeNull()
        ->and($memory->cappedBy)->toBeNull()
        ->and($memory->settlingWorkers)->toBe(0)
        ->and($memory->oomBackoffCeiling)->toBeNull()
        ->and($memory->oomBackoffUntil)->toBeNull();
});

test('a scope string it does not know is read as no scope rather than failing', function (): void {
    $memory = MemoryBreakdown::fromDetails(['memory_scope' => 'pod', 'memory_capped_by' => 42]);

    expect($memory->scope)->toBeNull()
        ->and($memory->cappedBy)->toBeNull()
        ->and($memory->estimateSource)->toBe('unknown');
});

test('a null OOM ceiling and expiry stay null, and a numeric-string ceiling is read as an int', function (): void {
    $none = MemoryBreakdown::fromDetails(['oom_backoff_ceiling' => null, 'oom_backoff_until' => null]);
    $stringy = MemoryBreakdown::fromDetails(['oom_backoff_ceiling' => '7', 'oom_backoff_until' => 1_700_000_000]);

    expect($none->oomBackoffCeiling)->toBeNull()
        ->and($none->oomBackoffUntil)->toBeNull()
        ->and($stringy->oomBackoffCeiling)->toBe(7)
        ->and($stringy->oomBackoffUntil)->toBeNull();
});

test('used and free memory are derived from the percentage and the total', function (): void {
    $memory = new MemoryBreakdown(currentMemoryPercent: 62.5, totalMemoryMb: 4_000.0);

    expect($memory->usedMb())->toBe(2_500.0)
        ->and($memory->freeMb())->toBe(1_500.0);
});

test('free memory never goes negative when usage reads above the total', function (): void {
    expect((new MemoryBreakdown(currentMemoryPercent: 120.0, totalMemoryMb: 1_000.0))->freeMb())->toBe(0.0);
});

test('a result without memory details yields the empty breakdown', function (): void {
    $result = new CapacityCalculationResult(
        maxWorkersByCpu: 5,
        maxWorkersByMemory: 5,
        maxWorkersByConfig: 10,
        finalMaxWorkers: 5,
        limitingFactor: LimitingFactor::Balanced,
        details: ['memory_details' => 'not an array'],
    );

    expect($result->memoryBreakdown())->toEqual(new MemoryBreakdown);
});

test('scope kinds keep the strings consumers read from memory_scope, most specific first', function (): void {
    expect(array_map(fn (MemoryScopeKind $kind): string => $kind->value, MemoryScopeKind::byPrecedence()))
        ->toBe(['configured', 'cgroup', 'host', 'fallback']);
});
