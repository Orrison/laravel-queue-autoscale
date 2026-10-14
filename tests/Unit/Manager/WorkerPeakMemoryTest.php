<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Contracts\ClusterStoreContract;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Scaling\ResourceEstimateResolver;
use Cbox\LaravelQueueAutoscale\Testing\FakeClusterStore;
use Cbox\LaravelQueueAutoscale\Workers\WorkerMemoryPeakReader;
use Cbox\LaravelQueueAutoscale\Workers\WorkerPool;
use Cbox\LaravelQueueAutoscale\Workers\WorkerProcess;
use Cbox\SystemMetrics\DTO\Metrics\LimitSource;
use Cbox\SystemMetrics\DTO\Metrics\Memory\MemorySnapshot;
use Cbox\SystemMetrics\DTO\Metrics\SystemLimits;
use Cbox\SystemMetrics\Testing\FakeSystemMetrics;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    config()->set('queue-autoscale.limits.worker_memory_mb_estimate', 128);
    config()->set('queue-autoscale.queues', []);

    $this->procRoot = sys_get_temp_dir().'/autoscale-proc-'.bin2hex(random_bytes(4));
    mkdir($this->procRoot);

    $this->processes = [];
});

afterEach(function (): void {
    foreach ($this->processes as $process) {
        $process->stop(0);
    }

    foreach (glob("{$this->procRoot}/*/status") ?: [] as $status) {
        @unlink($status);
        @rmdir(dirname($status));
    }

    @rmdir($this->procRoot);
});

/**
 * Starts a worker process and gives it a fake /proc status with the given peak.
 */
function workerWithPeak(object $test, string $code, int $peakKb): Process
{
    $process = new Process([PHP_BINARY, '-r', $code]);
    $process->start();
    $test->processes[] = $process;

    $pid = (int) $process->getPid();
    mkdir("{$test->procRoot}/{$pid}");
    file_put_contents("{$test->procRoot}/{$pid}/status", "Name:\tphp\nVmHWM:\t  {$peakKb} kB\n");

    return $process;
}

function peakMemoryManager(object $test): AutoscaleManager
{
    return app()->make(AutoscaleManager::class, ['peakReader' => new WorkerMemoryPeakReader($test->procRoot)]);
}

function peakMemoryPool(AutoscaleManager $manager): WorkerPool
{
    return (new ReflectionProperty($manager, 'pool'))->getValue($manager);
}

it('feeds live workers\' peak memory to the resolver at the start of each cycle', function (): void {
    $live = workerWithPeak($this, 'sleep(10);', 307_200);
    $exited = workerWithPeak($this, 'exit(0);', 4_096_000);
    $exited->wait();

    $manager = peakMemoryManager($this);
    peakMemoryPool($manager)->add(new WorkerProcess($live, 'redis', 'emails', now()));
    peakMemoryPool($manager)->add(new WorkerProcess($exited, 'redis', 'emails', now()));

    (new ReflectionMethod($manager, 'beginEvaluationCycle'))->invoke($manager);

    /** @var ResourceEstimateResolver $resolver */
    $resolver = (new ReflectionProperty($manager, 'resolver'))->getValue($manager);

    // The exited worker's peak is ignored; only live workers count.
    expect($resolver->resolve('redis', 'emails')->memoryMbPerWorker)->toBe(300.0)
        ->and($resolver->hostEstimate()->memoryMbPerWorker)->toBe(300.0);
});

it('reports cluster capacity using what this host\'s workers actually peak at', function (): void {
    Event::fake();

    $fakes = FakeSystemMetrics::install();
    $fakes->limits->set(new SystemLimits(
        source: LimitSource::HOST,
        cpuCores: 64.0,
        memoryBytes: 10_240 * 1024 * 1024,
        currentCpuCores: 0.0,
        currentMemoryBytes: 0.0,
    ));

    // Capacity reads the host's own MemTotal/MemAvailable scope: 10 GB, half in use.
    $fakes->memory->set(new MemorySnapshot(
        totalBytes: 10_240 * 1024 * 1024,
        freeBytes: 5_120 * 1024 * 1024,
        availableBytes: 5_120 * 1024 * 1024,
        usedBytes: 5_120 * 1024 * 1024,
        buffersBytes: 0,
        cachedBytes: 0,
        swapTotalBytes: 0,
        swapFreeBytes: 0,
        swapUsedBytes: 0,
    ));

    $store = (new FakeClusterStore)->withLeader('another-manager');
    app()->instance(ClusterStoreContract::class, $store);
    app()->forgetInstance(AutoscaleManager::class);

    try {
        $manager = peakMemoryManager($this);

        // Spawned well outside the settle window, so its memory is already in
        // the host's usage rather than charged again as a booting worker.
        peakMemoryPool($manager)->add(new WorkerProcess(workerWithPeak($this, 'sleep(10);', 1_048_576), 'redis', 'emails', now()->subMinutes(5)));

        (new ReflectionMethod($manager, 'runClusterCycle'))->invoke($manager);

        $reported = collect($store->activeManagers())->first(fn ($state): bool => $state->managerId !== 'another-manager');

        // 85% of 10 GB less the 5 GB in use is 3584 MB of headroom: three more
        // 1 GB workers, not 28 at the 128 MB default.
        expect($reported->maxWorkers)->toBe(1 + 3);
    } finally {
        FakeSystemMetrics::uninstall();
    }
});
