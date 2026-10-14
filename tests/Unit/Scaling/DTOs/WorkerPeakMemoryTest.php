<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\DTOs\WorkerPeakMemory;

/**
 * Peaks 100, 200, ... n*100 MB, handed over in descending order so the
 * statistic has to sort them itself.
 *
 * @return non-empty-list<float>
 */
function ascendingPeaks(int $count): array
{
    $peaks = [];

    for ($i = $count; $i >= 1; $i--) {
        $peaks[] = $i * 100.0;
    }

    return $peaks;
}

test('below ten workers the floor is the lower median of the sorted peaks', function (int $workers, float $expected): void {
    $peak = WorkerPeakMemory::fromPeaks(ascendingPeaks($workers));

    expect($peak->mb)->toBe($expected)
        ->and($peak->workers)->toBe($workers);
})->with([
    // intdiv(n - 1, 2): the single sample, then the lower of the middle pair.
    'one worker' => [1, 100.0],
    'two workers' => [2, 100.0],
    'three workers' => [3, 200.0],
    'eight workers' => [8, 400.0],
    'nine workers' => [9, 500.0],
]);

test('from ten workers the floor is the nearest-rank 90th percentile', function (int $workers, float $expected): void {
    $peak = WorkerPeakMemory::fromPeaks(ascendingPeaks($workers));

    expect($peak->mb)->toBe($expected)
        ->and($peak->workers)->toBe($workers);
})->with([
    // ceil(0.9 n) - 1: the ninth of ten, the tenth of eleven, the eighteenth of twenty.
    'ten workers' => [10, 900.0],
    'eleven workers' => [11, 1_000.0],
    'twenty workers' => [20, 1_800.0],
]);

test('the statistic switches from the median to p90 between nine and ten workers', function (): void {
    // Nine workers at 200 MB and one at 2000 MB: the outlier is ignored on
    // both sides of the switch, so adding the tenth worker does not jump the floor.
    $nine = WorkerPeakMemory::fromPeaks([...array_fill(0, 8, 200.0), 2_000.0]);
    $ten = WorkerPeakMemory::fromPeaks([...array_fill(0, 9, 200.0), 2_000.0]);

    expect($nine->mb)->toBe(200.0)
        ->and($ten->mb)->toBe(200.0);
});

test('two outliers in ten workers do set the floor', function (): void {
    // p90 of ten is the ninth-lowest sample: one outlier is ignored, two are not.
    $peak = WorkerPeakMemory::fromPeaks([...array_fill(0, 8, 200.0), 2_000.0, 2_100.0]);

    expect($peak->mb)->toBe(2_000.0);
});

test('a default instance means nothing was measured', function (): void {
    $peak = new WorkerPeakMemory;

    expect($peak->mb)->toBe(0.0)
        ->and($peak->workers)->toBe(0);
});
