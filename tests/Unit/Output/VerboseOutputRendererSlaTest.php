<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Output\DataTransferObjects\OutputData;
use Cbox\LaravelQueueAutoscale\Output\DataTransferObjects\QueueStats;
use Cbox\LaravelQueueAutoscale\Output\Renderers\VerboseOutputRenderer;
use Cbox\LaravelQueueAutoscale\Scaling\SlaSignal;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The verbose console names the reading a status was judged on. It used to
 * print the oldest job's age, so a queue on SQS breaching on its pickup
 * percentile was shown as `oldest_age=0s >= SLA=30s`.
 */
function renderSlaStats(string $slaStatus, int $oldestJobAge, ?SlaSignal $slaSignal): string
{
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);

    (new VerboseOutputRenderer($output))->render(new OutputData(
        queueStats: [new QueueStats(
            connection: 'sqs',
            queue: 'orders',
            depth: 40,
            pending: 40,
            throughputPerMinute: 0.0,
            oldestJobAge: $oldestJobAge,
            slaTarget: 30,
            slaStatus: $slaStatus,
            activeWorkers: 1,
            targetWorkers: 2,
            slaSignal: $slaSignal,
        )],
        workers: [],
        scalingLog: [],
        timestamp: new DateTimeImmutable,
    ));

    return $output->fetch();
}

test('a breach shows the signal it was judged on', function (): void {
    expect(renderSlaStats('breached', 0, SlaSignal::fromPickupPercentile(45.0, 95)))
        ->toContain('SLA BREACH: p95 pickup=45.0s >= SLA=30s')
        ->not->toContain('oldest_age=0s >=');
});

test('a breach held through an inconclusive reading says so', function (): void {
    expect(renderSlaStats('breached', 0, SlaSignal::fromOldestJobAgeFallback(0)))
        ->toContain('SLA BREACH held (inconclusive): oldest_age=0s, SLA=30s')
        ->not->toContain('>= SLA');
});

test('a warning shows the signal it was judged on', function (): void {
    expect(renderSlaStats('warning', 0, SlaSignal::fromPickupPercentile(26.0, 95)))
        ->toContain('SLA WARNING: p95 pickup=26.0s approaching SLA=30s');
});

test('stats built without a signal fall back to the oldest job age', function (): void {
    expect(renderSlaStats('breached', 42, null))->toContain('SLA BREACH: oldest_age=42s >= SLA=30s')
        ->and(renderSlaStats('ok', 3, null))->not->toContain('SLA');
});

test('queue stats report a held breach only when the reading is under the target', function (): void {
    $stats = fn (string $status, ?SlaSignal $signal): QueueStats => new QueueStats('sqs', 'orders', 1, 1, 0.0, 0, 30, $status, 0, 0, slaSignal: $signal);

    expect($stats('breached', SlaSignal::fromOldestJobAgeFallback(0))->isBreachHeld())->toBeTrue()
        ->and($stats('breached', SlaSignal::fromPickupPercentile(30.0, 95))->isBreachHeld())->toBeFalse()
        ->and($stats('ok', SlaSignal::fromOldestJobAgeFallback(0))->isBreachHeld())->toBeFalse()
        ->and($stats('breached', null)->isBreachHeld())->toBeFalse();
});
