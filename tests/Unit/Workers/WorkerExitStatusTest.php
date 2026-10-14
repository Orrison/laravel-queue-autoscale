<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Workers\WorkerExitStatus;
use Cbox\LaravelQueueAutoscale\Workers\WorkerProcess;
use Symfony\Component\Process\Process;

function exitedWorker(string $code): WorkerProcess
{
    $process = new Process([PHP_BINARY, '-r', $code]);
    $process->run();

    return new WorkerProcess($process, 'redis', 'default', now()->subSeconds(30));
}

test('reports the exit code of a worker that exited on its own', function (): void {
    $exit = WorkerExitStatus::of(exitedWorker('exit(3);'));

    expect($exit->exitCode)->toBe(3)
        ->and($exit->termSignal)->toBeNull()
        ->and($exit->terminationRequested)->toBeFalse()
        ->and($exit->uptimeSeconds)->toBeGreaterThanOrEqual(30)
        ->and($exit->isGraceful())->toBeFalse();
});

test('treats a clean exit and the queue:work memory limit exit as graceful', function (int $code): void {
    expect(WorkerExitStatus::of(exitedWorker("exit({$code});"))->isGraceful())->toBeTrue();
})->with([0, 12]);

test('reports the signal that killed a worker', function (): void {
    $process = new Process([PHP_BINARY, '-r', 'sleep(10);']);
    $process->start();
    $worker = new WorkerProcess($process, 'redis', 'default', now());

    posix_kill((int) $process->getPid(), SIGKILL);

    while ($process->isRunning()) {
        usleep(10_000);
    }

    $exit = WorkerExitStatus::of($worker);

    expect($exit->termSignal)->toBe(SIGKILL)
        ->and($exit->isGraceful())->toBeFalse();
})->skip(! extension_loaded('posix'), 'requires posix');

test('reports whether the manager had asked the worker to stop', function (): void {
    $worker = exitedWorker('exit(0);');
    $worker->markTerminationRequested(now(), 30);

    expect(WorkerExitStatus::of($worker)->terminationRequested)->toBeTrue();
});

test('reports no signal when the platform cannot say which one', function (): void {
    // PHP built with --enable-sigchild throws from getTermSignal() when the signal is unknown.
    $process = Mockery::mock(Process::class);
    $process->shouldReceive('getPid')->andReturn(4242);
    $process->shouldReceive('hasBeenSignaled')->andReturn(true);
    $process->shouldReceive('getTermSignal')->andThrow(new RuntimeException('signal unknown'));
    $process->shouldReceive('getExitCode')->andReturn(null);

    $exit = WorkerExitStatus::of(new WorkerProcess($process, 'redis', 'default', now()));

    expect($exit->termSignal)->toBeNull()
        ->and($exit->isGraceful())->toBeFalse();
});

test('a queue:work error exit is not graceful', function (): void {
    // Worker::EXIT_ERROR: the worker stopped itself, but because something broke.
    expect(WorkerExitStatus::of(exitedWorker('exit(1);'))->isGraceful())->toBeFalse();
});

test('a worker the manager asked to stop that died of SIGTERM is requested but not graceful', function (): void {
    $process = new Process([PHP_BINARY, '-r', 'fwrite(STDOUT, "ready\n"); sleep(10);']);
    $process->start();

    $deadline = microtime(true) + 5.0;
    while ($process->getOutput() === '' && microtime(true) < $deadline) {
        usleep(10_000);
    }

    $worker = new WorkerProcess($process, 'redis', 'default', now());
    $worker->markTerminationRequested(now(), 30);
    posix_kill((int) $process->getPid(), SIGTERM);

    while ($process->isRunning()) {
        usleep(10_000);
    }

    $exit = WorkerExitStatus::of($worker);

    // Symfony reports a signalled exit as 128 + the signal.
    expect($exit->termSignal)->toBe(SIGTERM)
        ->and($exit->exitCode)->toBe(128 + SIGTERM)
        ->and($exit->terminationRequested)->toBeTrue()
        ->and($exit->isGraceful())->toBeFalse();
})->skip(! extension_loaded('posix'), 'requires posix');

test('a signal makes an exit ungraceful even alongside a clean exit code', function (): void {
    $process = Mockery::mock(Process::class);
    $process->shouldReceive('getPid')->andReturn(4242);
    $process->shouldReceive('hasBeenSignaled')->andReturn(true);
    $process->shouldReceive('getTermSignal')->andReturn(SIGKILL);
    $process->shouldReceive('getExitCode')->andReturn(0);

    $exit = WorkerExitStatus::of(new WorkerProcess($process, 'redis', 'default', now()));

    expect($exit->termSignal)->toBe(SIGKILL)
        ->and($exit->isGraceful())->toBeFalse();
});

test('serialises to the log context keys', function (): void {
    $exit = new WorkerExitStatus(exitCode: 137, termSignal: 9, terminationRequested: false, uptimeSeconds: 42);

    expect($exit->toArray())->toBe([
        'exit_code' => 137,
        'term_signal' => 9,
        'termination_requested' => false,
        'uptime_seconds' => 42,
    ]);
});
