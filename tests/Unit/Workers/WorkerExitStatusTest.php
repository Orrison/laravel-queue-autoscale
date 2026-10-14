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
