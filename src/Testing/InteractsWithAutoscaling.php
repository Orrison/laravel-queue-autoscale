<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Testing;

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ClusterStoreContract;
use Cbox\LaravelQueueAutoscale\Contracts\FailureWindowStoreContract;
use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingEngine;
use Cbox\LaravelQueueAutoscale\Scaling\SlaSignal;
use Cbox\LaravelQueueAutoscale\Scaling\SlaSignalResolver;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;
use PHPUnit\Framework\Assert;

/**
 * Test helpers for applications that configure this package.
 *
 * The question a consumer wants to answer is "given this queue in this state,
 * how many workers does my configuration ask for" — and answering it directly
 * means assembling an engine, a config, a metrics DTO and two stores. This
 * trait does that assembly, so a spec is one line of intent and one assertion.
 *
 * Everything here drives the real ScalingEngine with the application's real
 * configuration. Only the two stores are substituted, and only because they
 * would otherwise need Redis.
 */
trait InteractsWithAutoscaling
{
    /**
     * The in-memory window store this test installed, if it installed one.
     *
     * Held here rather than read back out of the container: the binding is
     * swapped at runtime by fakeFailureWindows(), which static analysis cannot
     * see, so resolving the contract to ask what it is now reports the default
     * concrete store and makes the check look dead.
     */
    private ?InMemoryFailureWindowStore $installedFailureWindowStore = null;

    /**
     * Replace the fuse's window store with an in-memory one.
     *
     * Returns it, so a spec can seed failures and drive the fuse through its
     * states without waiting for real cooldowns.
     */
    protected function fakeFailureWindows(): InMemoryFailureWindowStore
    {
        $store = new InMemoryFailureWindowStore;
        app()->instance(FailureWindowStoreContract::class, $store);

        return $this->installedFailureWindowStore = $store;
    }

    /**
     * Replace the cluster store with an in-memory one.
     */
    protected function fakeCluster(): FakeClusterStore
    {
        $store = new FakeClusterStore;
        app()->instance(ClusterStoreContract::class, $store);

        return $store;
    }

    /**
     * Replace the pickup-time store with an in-memory one.
     *
     * Returns it, so a spec can seed pickup times and see the strategy and the
     * SLA breach state react to a measured percentile — the only signal a
     * driver like Amazon SQS has, since it cannot report the oldest job's age.
     *
     * The strategy, engine and manager are resolved again on next use, because
     * each holds the store it was built with.
     */
    protected function fakePickupTimes(): InMemoryPickupTimeStore
    {
        $store = new InMemoryPickupTimeStore;
        app()->instance(PickupTimeStoreContract::class, $store);

        app()->forgetInstance(ScalingStrategyContract::class);
        app()->forgetInstance(SlaSignalResolver::class);
        app()->forgetInstance(ScalingEngine::class);
        app()->forgetInstance(AutoscaleManager::class);

        return $store;
    }

    /**
     * The SLA signal a queue in this state is judged on: what SlaBreached,
     * SlaRecovered and the console's breach status are decided from.
     *
     * A pinned queue never runs the strategy, so it is measured the way the
     * manager measures it rather than through a demand calculation.
     */
    protected function slaSignalFor(QueueMetricsData $metrics, ?QueueConfiguration $config = null): SlaSignal
    {
        $config ??= QueueConfiguration::fromConfig($metrics->connection, $metrics->queue);
        $engine = app()->make(ScalingEngine::class);

        return $config->workers->scalable
            ? $engine->assessDemand($metrics, $config)->slaSignal
            : $engine->measureSlaSignal($metrics, $config);
    }

    /**
     * Assert a queue in this state is judged to be breaching its SLA.
     */
    protected function assertBreachesSla(QueueMetricsData $metrics, ?QueueConfiguration $config = null): void
    {
        $config ??= QueueConfiguration::fromConfig($metrics->connection, $metrics->queue);
        $signal = $this->slaSignalFor($metrics, $config);

        Assert::assertTrue(
            $signal->isBreaching($config->sla->targetSeconds),
            "Expected {$metrics->connection}:{$metrics->queue} to breach its {$config->sla->targetSeconds}s SLA, "
            ."but it was judged on {$signal->describe()}."
        );
    }

    /**
     * Assert a queue in this state is judged to be within its SLA.
     */
    protected function assertWithinSla(QueueMetricsData $metrics, ?QueueConfiguration $config = null): void
    {
        $config ??= QueueConfiguration::fromConfig($metrics->connection, $metrics->queue);
        $signal = $this->slaSignalFor($metrics, $config);

        Assert::assertFalse(
            $signal->isBreaching($config->sla->targetSeconds),
            "Expected {$metrics->connection}:{$metrics->queue} to be within its {$config->sla->targetSeconds}s SLA, "
            ."but it was judged on {$signal->describe()}."
        );
    }

    /**
     * The worker count the autoscaler asks for, given a queue in this state.
     *
     * This is cluster-wide demand: the strategy's answer bounded by the
     * queue's own min and max and by the fuse. It deliberately does not apply
     * host CPU and memory limits, because those depend on the machine running
     * the test and would make the same configuration assert differently on a
     * laptop and in CI. To test the host ceiling itself, use CapacityCalculator
     * directly.
     */
    protected function workersDemandedFor(QueueMetricsData $metrics, ?QueueConfiguration $config = null): int
    {
        $config ??= QueueConfiguration::fromConfig($metrics->connection, $metrics->queue);

        return app()->make(ScalingEngine::class)->evaluateDemand($metrics, $config);
    }

    /**
     * Assert the configuration asks for a specific worker count.
     */
    protected function assertWorkersDemanded(int $expected, QueueMetricsData $metrics, ?QueueConfiguration $config = null): void
    {
        $actual = $this->workersDemandedFor($metrics, $config);

        Assert::assertSame(
            $expected,
            $actual,
            "Expected {$metrics->connection}:{$metrics->queue} to demand {$expected} workers, got {$actual}."
        );
    }

    /**
     * Assert a queue can never exceed a worker count, however deep it gets.
     *
     * The assertion a downstream connection limit actually needs: not that a
     * particular backlog produces a particular number, but that no backlog
     * produces more than the cap.
     */
    protected function assertWorkersCappedAt(int $cap, string $queue, string $connection = 'redis'): void
    {
        $config = QueueConfiguration::fromConfig($connection, $queue);

        $deepest = 0;

        foreach ([10, 1_000, 100_000] as $pending) {
            $metrics = QueueMetricsFactory::behind($pending, oldestJobAge: 3_600, queue: $queue);
            $actual = $this->workersDemandedFor($metrics, $config);

            Assert::assertLessThanOrEqual(
                $cap,
                $actual,
                "{$connection}:{$queue} demanded {$actual} workers with {$pending} pending, above its cap of {$cap}."
            );

            $deepest = $actual;
        }

        // Without this the assertion is vacuous: a configuration that demands
        // zero workers for every backlog would "prove" the cap holds. A cap is
        // only meaningful if the queue can actually reach it.
        Assert::assertSame(
            $cap,
            $deepest,
            "{$connection}:{$queue} never reached its cap of {$cap} even with 100,000 overdue jobs — "
            ."it demanded {$deepest}, so this assertion would pass for a queue that scales not at all."
        );
    }

    /**
     * Drive a queue's fuse to tripped, so a spec can assert what happens next.
     */
    protected function tripFuseFor(string $queue, string $connection = 'redis'): InMemoryFailureWindowStore
    {
        $store = $this->installedFailureWindowStore ?? $this->fakeFailureWindows();

        $config = QueueConfiguration::fromConfig($connection, $queue);
        $samples = max($config->fuse->minSamples, 1);

        $store->seedWindow($samples, $samples, $connection, $queue);

        return $store;
    }
}
