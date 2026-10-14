<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Scaling\ResourceEstimateResolver;
use Cbox\LaravelQueueAutoscale\Workers\WorkerMemoryPeakReader;
use Cbox\LaravelQueueAutoscale\Workers\WorkerPool;
use Cbox\LaravelQueueAutoscale\Workers\WorkerProcess;
use Symfony\Component\Process\Process;

it('feeds live workers\' peak memory to the resolver at the start of each cycle', function (): void {
    config()->set('queue-autoscale.limits.worker_memory_mb_estimate', 128);
    config()->set('queue-autoscale.queues', []);

    $process = new Process([PHP_BINARY, '-r', 'sleep(10);']);
    $process->start();
    $pid = (int) $process->getPid();

    $procRoot = sys_get_temp_dir().'/autoscale-proc-'.bin2hex(random_bytes(4));
    mkdir("{$procRoot}/{$pid}", recursive: true);
    file_put_contents("{$procRoot}/{$pid}/status", "Name:\tphp\nVmHWM:\t  307200 kB\n");

    try {
        $manager = app()->make(AutoscaleManager::class, ['peakReader' => new WorkerMemoryPeakReader($procRoot)]);

        /** @var WorkerPool $pool */
        $pool = (new ReflectionProperty($manager, 'pool'))->getValue($manager);
        $pool->add(new WorkerProcess($process, 'redis', 'emails', now()));

        (new ReflectionMethod($manager, 'beginEvaluationCycle'))->invoke($manager);

        /** @var ResourceEstimateResolver $resolver */
        $resolver = (new ReflectionProperty($manager, 'resolver'))->getValue($manager);

        expect($resolver->resolve('redis', 'emails')->memoryMbPerWorker)->toBe(300.0)
            ->and($resolver->hostEstimate()->memoryMbPerWorker)->toBe(300.0);
    } finally {
        $process->stop(0);
        @unlink("{$procRoot}/{$pid}/status");
        @rmdir("{$procRoot}/{$pid}");
        @rmdir($procRoot);
    }
});
