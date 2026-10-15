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

test('a zero fallback reading from a driver that reports ages is conclusive', function (): void {
    expect(SlaSignal::fromOldestJobAgeFallback(0)->isInconclusive(pending: 10, driverReportsOldestJobAge: true))->toBeFalse()
        ->and(SlaSignal::fromOldestJobAgeFallback(0)->isInconclusive(pending: 10, driverReportsOldestJobAge: false))->toBeTrue();
});

test('only redis and database report the oldest job age', function (string $driver, bool $reports): void {
    expect(SlaSignal::driverReportsOldestJobAge($driver))->toBe($reports);
})->with([
    'redis' => ['redis', true],
    'database' => ['database', true],
    'sqs' => ['sqs', false],
    'beanstalkd' => ['beanstalkd', false],
    'unknown' => ['unknown', false],
    'empty' => ['', false],
]);

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

test('a stalled window raises the percentile to the head job wait', function (): void {
    // Nobody picked anything up for 40s while jobs wait; the head job is 35s old.
    $signal = SlaSignal::fromPickupPercentile(1.5, 95, secondsSinceLastPickup: 40.0)
        ->withStallEvidence(oldestJobAge: 35, pending: 10, slaTargetSeconds: 30);

    expect($signal->source)->toBe(SlaSignalSource::OldestJobAge)
        ->and($signal->seconds)->toBe(35.0)
        ->and($signal->percentile)->toBeNull()
        ->and($signal->isBreaching(30))->toBeTrue();
});

test('a head job aged by its delay is capped at the time since the last pickup', function (): void {
    // Redis ages the head from dispatch: a 300s delay reads as 300s of waiting.
    $signal = SlaSignal::fromPickupPercentile(1.5, 95, secondsSinceLastPickup: 31.0)
        ->withStallEvidence(oldestJobAge: 300, pending: 4, slaTargetSeconds: 30);

    expect($signal->seconds)->toBe(31.0)
        ->and($signal->source)->toBe(SlaSignalSource::OldestJobAge);
});

test('without evidence of a stall the percentile stands', function (?float $sinceLastPickup, int $oldestJobAge, int $pending): void {
    $percentile = SlaSignal::fromPickupPercentile(2.0, 95, $sinceLastPickup);

    expect($percentile->withStallEvidence($oldestJobAge, $pending, slaTargetSeconds: 30))->toBe($percentile);
})->with([
    'pickups still arriving, delayed head' => [5.0, 400, 10],
    'pickups stopped just under the target' => [29.9, 400, 10],
    'nothing pending' => [120.0, 0, 0],
    'head no older than the percentile' => [120.0, 2, 10],
    'no last-pickup time (custom strategy)' => [null, 400, 10],
]);

test('only a pickup percentile is raised by stall evidence', function (): void {
    $fallback = SlaSignal::fromOldestJobAgeFallback(0);
    $plain = SlaSignal::fromOldestJobAge(10);

    expect($fallback->withStallEvidence(400, 10, 30))->toBe($fallback)
        ->and($plain->withStallEvidence(400, 10, 30))->toBe($plain);
});
