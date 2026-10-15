<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\SlaSignal;
use Cbox\LaravelQueueAutoscale\Scaling\SlaSignalSource;

test('a bare signal means nothing is waiting', function (): void {
    $signal = new SlaSignal;

    expect($signal->seconds)->toBe(0.0)
        ->and($signal->source)->toBe(SlaSignalSource::OldestJobAge)
        ->and($signal->percentile)->toBeNull()
        ->and($signal->isBreaching(30))->toBeFalse()
        ->and($signal->isWarning(30))->toBeFalse();
});

test('each factory records where the number came from', function (): void {
    expect(SlaSignal::fromOldestJobAge(12))
        ->seconds->toBe(12.0)
        ->source->toBe(SlaSignalSource::OldestJobAge)
        ->percentile->toBeNull();

    expect(SlaSignal::fromOldestJobAgeFallback(12))
        ->seconds->toBe(12.0)
        ->source->toBe(SlaSignalSource::OldestJobAgeFallback);

    expect(SlaSignal::fromPickupPercentile(41.5, 99))
        ->seconds->toBe(41.5)
        ->source->toBe(SlaSignalSource::PickupPercentile)
        ->percentile->toBe(99);
});

test('a signal at or over the target is breaching', function (float $seconds, bool $breaching): void {
    expect(SlaSignal::fromPickupPercentile($seconds, 95)->isBreaching(30))->toBe($breaching);
})->with([
    'under' => [29.99, false],
    'at' => [30.0, true],
    'over' => [45.0, true],
]);

test('a zero reading never breaches, however small the target', function (): void {
    // An empty queue reports an oldest age of zero. Without the guard that
    // would read as "infinitely late" against any target.
    expect(SlaSignal::fromOldestJobAge(0)->isBreaching(1))->toBeFalse()
        ->and(SlaSignal::fromPickupPercentile(0.0, 95)->isBreaching(1))->toBeFalse();
});

test('the warning band is strictly above 80 percent and strictly below the target', function (float $seconds, bool $warning): void {
    expect(SlaSignal::fromPickupPercentile($seconds, 95)->isWarning(30))->toBe($warning);
})->with([
    'at 80%' => [24.0, false],
    'just over 80%' => [24.1, true],
    'just under target' => [29.9, true],
    'at target (breached, not warning)' => [30.0, false],
]);

test('only a zero fallback reading with work still pending is inconclusive', function (): void {
    expect(SlaSignal::fromOldestJobAgeFallback(0)->isInconclusive(pending: 10))->toBeTrue()
        // Nothing waiting: the queue really has recovered.
        ->and(SlaSignal::fromOldestJobAgeFallback(0)->isInconclusive(pending: 0))->toBeFalse()
        // A real age is evidence either way.
        ->and(SlaSignal::fromOldestJobAgeFallback(5)->isInconclusive(pending: 10))->toBeFalse()
        // A strategy that only ever reads the age keeps its old semantics.
        ->and(SlaSignal::fromOldestJobAge(0)->isInconclusive(pending: 10))->toBeFalse()
        // A measured percentile is never inconclusive.
        ->and(SlaSignal::fromPickupPercentile(0.0, 95)->isInconclusive(pending: 10))->toBeFalse();
});

test('it describes itself the way the console logs it', function (): void {
    expect(SlaSignal::fromPickupPercentile(42.04, 95)->describe())->toBe('p95 pickup=42.0s')
        ->and(SlaSignal::fromPickupPercentile(3.0, 99)->describe())->toBe('p99 pickup=3.0s')
        ->and(SlaSignal::fromOldestJobAge(42)->describe())->toBe('oldest_age=42s')
        ->and(SlaSignal::fromOldestJobAgeFallback(7)->describe())->toBe('oldest_age=7s');
});

test('source values are stable strings for summaries and telemetry', function (): void {
    expect(SlaSignalSource::PickupPercentile->value)->toBe('pickup_percentile')
        ->and(SlaSignalSource::OldestJobAge->value)->toBe('oldest_job_age')
        ->and(SlaSignalSource::OldestJobAgeFallback->value)->toBe('oldest_job_age_fallback');
});

test('a head job older than the pickup percentile raises the signal to its age', function (): void {
    $signal = SlaSignal::fromPickupPercentile(1.5, 95)->withOldestJobAge(200);

    expect($signal->source)->toBe(SlaSignalSource::OldestJobAge)
        ->and($signal->seconds)->toBe(200.0)
        ->and($signal->percentile)->toBeNull()
        ->and($signal->isBreaching(30))->toBeTrue();
});

test('a head job no older than the pickup percentile leaves the percentile in charge', function (int $oldestJobAge): void {
    $percentile = SlaSignal::fromPickupPercentile(45.0, 95);

    expect($percentile->withOldestJobAge($oldestJobAge))->toBe($percentile);
})->with([
    'empty queue' => [0],
    'younger head' => [12],
    'equal' => [45],
]);

test('an oldest-age signal is already the head job and is left as it is', function (): void {
    $fallback = SlaSignal::fromOldestJobAgeFallback(0);
    $plain = SlaSignal::fromOldestJobAge(10);

    expect($fallback->withOldestJobAge(0))->toBe($fallback)
        ->and($plain->withOldestJobAge(10))->toBe($plain);
});
