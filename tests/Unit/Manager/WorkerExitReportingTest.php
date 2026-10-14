<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Events\WorkerExited;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Workers\WorkerPool;
use Cbox\LaravelQueueAutoscale\Workers\WorkerProcess;
use Cbox\LaravelQueueAutoscale\Workers\WorkerScaler;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * @param  list<array{level: string, message: string, context: array<string, mixed>}>  $logged
 */
function captureManagerLogs(array &$logged): void
{
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('log')->andReturnUsing(function (string $level, string $message, array $context = []) use (&$logged): void {
        $logged[] = compact('level', 'message', 'context');
    });

    foreach (['debug', 'info', 'notice', 'warning', 'error'] as $level) {
        Log::shouldReceive($level)->andReturnUsing(function (string $message, array $context = []) use (&$logged, $level): void {
            $logged[] = compact('level', 'message', 'context');
        });
    }
}

/**
 * @param  list<array{level: string, message: string, context: array<string, mixed>}>  $logged
 * @return list<array{level: string, message: string, context: array<string, mixed>}>
 */
function loggedMessage(array $logged, string $message): array
{
    return array_values(array_filter($logged, fn (array $entry): bool => $entry['message'] === $message));
}

function exitReportingPool(AutoscaleManager $manager): WorkerPool
{
    return (new ReflectionProperty($manager, 'pool'))->getValue($manager);
}

function exitReportingScaler(AutoscaleManager $manager): WorkerScaler
{
    return (new ReflectionProperty($manager, 'scaler'))->getValue($manager);
}

function startedWorkerProcess(string $code): Process
{
    $process = new Process([PHP_BINARY, '-r', $code]);
    $process->start();

    return $process;
}

beforeEach(function (): void {
    Event::fake([WorkerExited::class]);
});

it('logs how a crashed worker exited at warning level and announces it', function (): void {
    $logged = [];
    captureManagerLogs($logged);

    $manager = app(AutoscaleManager::class);
    $process = startedWorkerProcess('exit(3);');
    $process->wait();
    exitReportingPool($manager)->add(new WorkerProcess($process, 'redis', 'emails', now(), group: null));

    exitReportingScaler($manager)->cleanupDeadWorkers();

    $removed = loggedMessage($logged, 'Removed dead worker');

    expect($removed)->toHaveCount(1)
        ->and($removed[0]['level'])->toBe('warning')
        ->and($removed[0]['context'])->toMatchArray([
            'connection' => 'redis',
            'queue' => 'emails',
            'exit_code' => 3,
            'term_signal' => null,
            'termination_requested' => false,
        ]);

    Event::assertDispatched(WorkerExited::class, fn (WorkerExited $event): bool => $event->queue === 'emails'
        && $event->exitCode === 3
        && $event->graceful === false);
});

it('logs a worker that stopped itself cleanly at info level', function (): void {
    $logged = [];
    captureManagerLogs($logged);

    $manager = app(AutoscaleManager::class);
    $process = startedWorkerProcess('exit(0);');
    $process->wait();
    exitReportingPool($manager)->add(new WorkerProcess($process, 'redis', 'emails', now()));

    exitReportingScaler($manager)->cleanupDeadWorkers();

    $removed = loggedMessage($logged, 'Removed dead worker');

    expect($removed)->toHaveCount(1)
        ->and($removed[0]['level'])->toBe('info')
        ->and($removed[0]['context']['exit_code'])->toBe(0);

    Event::assertDispatched(WorkerExited::class, fn (WorkerExited $event): bool => $event->graceful);
});

it('forwards worker output and reports exits while the manager shuts down', function (): void {
    config()->set('queue-autoscale.queues.emails', ['workers' => ['shutdown_timeout_seconds' => 10]]);

    $logged = [];
    captureManagerLogs($logged);

    $manager = app(AutoscaleManager::class);

    // Finishes its "current job" well after SIGTERM, then writes its last
    // lines, the final one without a trailing newline.
    $process = startedWorkerProcess(
        '$stop = false; pcntl_async_signals(true); pcntl_signal(SIGTERM, function () use (&$stop) { $stop = true; });'
        .' fwrite(STDOUT, "ready\n"); while (! $stop) { usleep(20000); }'
        .' usleep(1500000); fwrite(STDERR, "finished last job\n"); fwrite(STDERR, "no trailing newline"); exit(0);'
    );

    $deadline = microtime(true) + 5.0;
    while ($process->getOutput() === '' && microtime(true) < $deadline) {
        usleep(20_000);
    }

    $pid = (int) $process->getPid();
    exitReportingPool($manager)->add(new WorkerProcess($process, 'redis', 'emails', now()));

    (new ReflectionMethod($manager, 'shutdown'))->invoke($manager);

    $messages = array_column($logged, 'message');

    expect($messages)->toContain("[worker {$pid}] finished last job")
        ->and($messages)->toContain("[worker {$pid}] no trailing newline");

    $removed = loggedMessage($logged, 'Removed dead worker');

    expect($removed)->toHaveCount(1)
        ->and($removed[0]['context']['pid'])->toBe($pid)
        ->and($removed[0]['context']['termination_requested'])->toBeTrue()
        ->and($removed[0]['context']['exit_code'])->toBe(0);
})->skip(! extension_loaded('pcntl') || ! extension_loaded('posix'), 'requires pcntl and posix');
