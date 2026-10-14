<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Events\AutoscaleManagerStopped;
use Cbox\LaravelQueueAutoscale\Events\WorkerExited;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Workers\WorkerPool;
use Cbox\LaravelQueueAutoscale\Workers\WorkerProcess;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Process\Process;

/**
 * Starts a worker and waits until it has written its first line, so any
 * signal handler it installs is in place before the test signals it.
 */
function readyShutdownWorker(string $code): Process
{
    $process = new Process([PHP_BINARY, '-r', $code]);
    $process->start();

    $deadline = microtime(true) + 5.0;
    while ($process->getOutput() === '' && microtime(true) < $deadline) {
        usleep(20_000);
    }

    return $process;
}

function shutdownWorkerPool(AutoscaleManager $manager): WorkerPool
{
    return (new ReflectionProperty($manager, 'pool'))->getValue($manager);
}

it('still force-kills workers and completes shutdown when a WorkerExited listener throws', function (): void {
    config()->set('queue-autoscale.queues.emails', ['workers' => ['shutdown_timeout_seconds' => 2]]);

    Event::fake([AutoscaleManagerStopped::class]);
    Event::listen(WorkerExited::class, function (): void {
        throw new RuntimeException('listener failed');
    });

    $manager = app(AutoscaleManager::class);
    $pool = shutdownWorkerPool($manager);

    // Stops on SIGTERM, so its exit is reported while the other is still
    // pending — which is when the throwing listener runs.
    $obedient = readyShutdownWorker('fwrite(STDOUT, "ready\n"); sleep(30);');

    // Ignores SIGTERM, so only the SIGKILL after the deadline stops it.
    $stubborn = readyShutdownWorker(
        'pcntl_async_signals(true); pcntl_signal(SIGTERM, SIG_IGN); fwrite(STDOUT, "ready\n"); while (true) { usleep(20000); }'
    );

    $pool->add(new WorkerProcess($obedient, 'redis', 'emails', now()));
    $pool->add(new WorkerProcess($stubborn, 'redis', 'emails', now()));

    try {
        (new ReflectionMethod($manager, 'shutdown'))->invoke($manager);

        expect($stubborn->isRunning())->toBeFalse()
            ->and($obedient->isRunning())->toBeFalse();

        Event::assertDispatched(AutoscaleManagerStopped::class);
    } finally {
        $obedient->stop(0);
        $stubborn->stop(0);
    }
})->skip(! extension_loaded('pcntl') || ! extension_loaded('posix'), 'requires pcntl and posix');
