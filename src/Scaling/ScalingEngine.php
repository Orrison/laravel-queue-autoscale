<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling;

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;
use Cbox\LaravelQueueAutoscale\Contracts\SlaSignalStrategy;
use Cbox\LaravelQueueAutoscale\Fuse\FailureFuse;
use Cbox\LaravelQueueAutoscale\Fuse\NullFailureWindowStore;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\CapacityCalculationResult;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\LimitingFactor;
use Cbox\LaravelQueueAutoscale\Scaling\Strategies\HybridStrategy;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;

readonly class ScalingEngine
{
    /**
     * The fuse lives here rather than inside a strategy so every strategy —
     * including custom ones — is protected from scaling into a downstream
     * outage. It defaults to a null-backed fuse so an engine constructed
     * by hand behaves exactly as it did before the fuse existed.
     *
     * The SLA signal resolver is only consulted for workloads the strategy
     * does not evaluate (pinned ones), and only when the strategy reports a
     * signal of its own. Left null, as on an engine built by hand, those
     * workloads are judged on the oldest job's age as they always were.
     */
    public function __construct(
        private ScalingStrategyContract $strategy,
        private CapacityCalculator $capacity,
        private ResourceEstimateResolver $resolver,
        private FailureFuse $fuse = new FailureFuse(new NullFailureWindowStore),
        private ?SlaSignalResolver $slaSignals = null,
    ) {}

    /**
     * Whether the failure fuse is currently constraining this queue.
     *
     * Exposed because the anti-flapping damper has to know. The fuse withdraws
     * workers from a queue whose jobs are failing, and damping that withdrawal
     * as an ordinary direction reversal leaves the fleet hammering a dead
     * dependency for the rest of the cooldown window — the one thing the fuse
     * exists to stop. A decision's limitingFactor is not a usable substitute:
     * it names the constraint that BOUND, so a tripped fuse is invisible there
     * whenever CPU or memory happens to bind tighter.
     *
     * Delegates to the fuse's transition-free query rather than evaluate(),
     * which is a state machine and not a question: running it twice in a cycle
     * can trip, probe or recover a second time and fire the event to match.
     */
    public function isFuseConstraining(QueueConfiguration $config): bool
    {
        return $this->fuse->isConstraining($config);
    }

    /**
     * Evaluate scaling decision for a single-host queue.
     *
     * Uses LOCAL system capacity (CPU/memory on this host) to constrain the
     * strategy recommendation. Both $totalPoolWorkers and the capacity result
     * must be from the SAME host — passing cluster-wide pool counts against
     * local capacity produces incorrect results. For cluster-wide demand
     * calculations use evaluateDemand() instead.
     *
     * @param  QueueMetricsData  $metrics  Queue metrics from laravel-queue-metrics
     * @param  QueueConfiguration  $config  Queue SLA configuration
     * @param  int  $currentWorkers  Current worker count for this queue on this host
     * @param  int  $totalPoolWorkers  Total workers across all queues on this host
     * @return ScalingDecision Scaling decision with target workers
     */
    public function evaluate(
        QueueMetricsData $metrics,
        QueueConfiguration $config,
        int $currentWorkers,
        int $totalPoolWorkers = 0,
    ): ScalingDecision {
        // 1. Calculate target workers based on strategy
        $strategyRecommendation = $this->strategy->calculateTargetWorkers($metrics, $config);
        $slaSignal = $this->signalOfLastCalculation($metrics, $config);
        $targetWorkers = $strategyRecommendation;

        // 2. Get LOCAL system capacity using total pool workers for accurate measurement.
        // This ensures capacity is calculated against ALL workers on THIS host,
        // preventing each queue from assuming it has all remaining system capacity.
        // Note: both $totalPoolWorkers and calculateMaxWorkers() must be local-host
        // scoped. In cluster mode, use evaluateDemand() instead.
        $effectiveTotalWorkers = max($totalPoolWorkers, $currentWorkers);
        $estimate = $this->resolver->resolve($config->connection, $config->queue);
        $capacityResult = $this->capacity->calculateMaxWorkers($effectiveTotalWorkers, $estimate);

        // 3. Apply resource constraints: this queue's share of system capacity
        // System can support capacityResult->finalMaxWorkers total. Other queues
        // already consume (totalPoolWorkers - currentWorkers), so this queue's
        // ceiling is systemMax minus what other queues are using.
        $otherQueuesWorkers = $effectiveTotalWorkers - $currentWorkers;
        $availableForThisQueue = max($capacityResult->finalMaxWorkers - $otherQueuesWorkers, 0);
        $targetWorkers = min($targetWorkers, $availableForThisQueue);

        // 4. Apply config bounds (min/max workers)
        $beforeConfigBounds = $targetWorkers;
        $targetWorkers = max($targetWorkers, $config->workers->min);
        $targetWorkers = min($targetWorkers, $config->workers->max);

        // 5. Apply the failure fuse. A downstream outage is indistinguishable
        // from load to every calculation above — jobs fail, get released, the
        // backlog grows — so without this the autoscaler would answer an
        // outage by adding workers to it.
        $fuseVerdict = $this->fuse->evaluate($config);
        $fuseCeiling = $fuseVerdict->workerCeiling($config->workers->min);

        if ($fuseCeiling !== null) {
            $targetWorkers = min($targetWorkers, max(0, min($fuseCeiling, $config->workers->max)));
        }

        // 6. Determine final limiting factor after all constraints
        $finalLimitingFactor = $fuseVerdict->isTripped()
            ? LimitingFactor::Fuse
            : $this->determineFinalLimitingFactor(
                $capacityResult,
                $strategyRecommendation,
                $beforeConfigBounds,
                $targetWorkers,
                $config->workers->min,
                $config->workers->max
            );

        // 7. Create final capacity result with config constraint applied
        $finalCapacityResult = new CapacityCalculationResult(
            maxWorkersByCpu: $capacityResult->maxWorkersByCpu,
            maxWorkersByMemory: $capacityResult->maxWorkersByMemory,
            maxWorkersByConfig: $config->workers->max,
            finalMaxWorkers: $targetWorkers,
            limitingFactor: $finalLimitingFactor,
            details: $capacityResult->details
        );

        return new ScalingDecision(
            connection: $config->connection,
            queue: $config->queue,
            currentWorkers: $currentWorkers,
            targetWorkers: $targetWorkers,
            reason: $this->composeReason($fuseVerdict->reason()),
            predictedPickupTime: $this->strategy->getLastPrediction(),
            slaTarget: $config->sla->targetSeconds,
            capacity: $finalCapacityResult,
            spawnCompensation: $config->spawnCompensation,
            slaSignal: $slaSignal,
        );
    }

    /**
     * Evaluate demand-only target for cluster-wide scaling decisions.
     *
     * Returns the strategy recommendation constrained only by config bounds
     * (workers.min / workers.max), WITHOUT applying system capacity constraints.
     * In cluster mode the leader must see actual demand so it can recommend
     * the right host count; per-host capacity enforcement happens during
     * distribution and at execution time on each manager.
     */
    public function evaluateDemand(
        QueueMetricsData $metrics,
        QueueConfiguration $config,
    ): int {
        return $this->assessDemand($metrics, $config)->targetWorkers;
    }

    /**
     * evaluateDemand(), plus the SLA signal the strategy computed the demand
     * from — what the cluster leader decides breach on.
     */
    public function assessDemand(
        QueueMetricsData $metrics,
        QueueConfiguration $config,
    ): DemandAssessment {
        $targetWorkers = $this->strategy->calculateTargetWorkers($metrics, $config);
        $slaSignal = $this->signalOfLastCalculation($metrics, $config);
        $targetWorkers = max($targetWorkers, $config->workers->min);
        $targetWorkers = min($targetWorkers, $config->workers->max);

        // The fuse constrains cluster-wide demand too: without it the leader
        // would keep recommending capacity that every host is refusing to
        // spawn, and the cluster would look permanently under-provisioned.
        // State transitions are idempotent, so it does not matter whether the
        // leader or a local evaluate() cycle observes the change first.
        $fuseCeiling = $this->fuse->evaluate($config)->workerCeiling($config->workers->min);

        if ($fuseCeiling !== null) {
            $targetWorkers = min($targetWorkers, max(0, min($fuseCeiling, $config->workers->max)));
        }

        return new DemandAssessment($targetWorkers, $slaSignal);
    }

    /**
     * The SLA signal of a workload the strategy does not evaluate.
     *
     * Pinned workloads hold a fixed worker count, so nothing runs the strategy
     * for them — but they still breach and recover, and must be judged the way
     * every other workload is. A strategy that reports signals gets the same
     * measurement it would have made; one that does not, and an engine built
     * without a resolver, get the oldest job's age, unchanged.
     */
    public function measureSlaSignal(QueueMetricsData $metrics, QueueConfiguration $config): SlaSignal
    {
        if ($this->strategy instanceof SlaSignalStrategy && $this->slaSignals !== null) {
            return $this->slaSignals->resolve($metrics, $config)->withOldestJobAge($metrics->oldestJobAge);
        }

        return SlaSignal::fromOldestJobAge($metrics->oldestJobAge);
    }

    /**
     * What the strategy reported for the calculation it just made, or the
     * oldest job's age when it reports nothing.
     *
     * Raised to the oldest job's age when that is larger, so breach is judged
     * on whichever of the two is further behind; see
     * SlaSignal::withOldestJobAge(). The strategy still scales on the signal
     * it computed.
     *
     * The shipped strategy is asked for the signal of this workload rather
     * than its last one, so a subclass that answers some workloads without
     * reaching the parent cannot pass another workload's signal off as this
     * one's. See HybridStrategy::slaSignalFor().
     */
    private function signalOfLastCalculation(QueueMetricsData $metrics, QueueConfiguration $config): SlaSignal
    {
        $reported = match (true) {
            $this->strategy instanceof HybridStrategy => $this->strategy->slaSignalFor($config),
            $this->strategy instanceof SlaSignalStrategy => $this->strategy->getLastSlaSignal(),
            default => null,
        };

        return ($reported ?? SlaSignal::fromOldestJobAge($metrics->oldestJobAge))
            ->withOldestJobAge($metrics->oldestJobAge);
    }

    /**
     * Prefix the strategy's explanation with the fuse note when it is tripped,
     * so operators reading a held queue see the cause before the arithmetic
     * that no longer applies.
     */
    private function composeReason(string $fuseReason): string
    {
        $strategyReason = $this->strategy->getLastReason();

        if ($fuseReason === '') {
            return $strategyReason;
        }

        return $strategyReason === ''
            ? $fuseReason
            : $fuseReason.'; '.$strategyReason;
    }

    /**
     * Determine which constraint is the final limiting factor
     */
    private function determineFinalLimitingFactor(
        CapacityCalculationResult $capacityResult,
        int $strategyRecommendation,
        int $afterSystemCapacity,
        int $finalTarget,
        int $configMinWorkers,
        int $configMaxWorkers,
    ): LimitingFactor {
        // If config max_workers capped the target
        if ($finalTarget < $afterSystemCapacity && $finalTarget === $configMaxWorkers) {
            return LimitingFactor::Config;
        }

        // If config min_workers raised the target above what strategy/capacity allowed
        // This means we have low/no demand, not a capacity constraint
        if ($finalTarget > $afterSystemCapacity && $finalTarget === $configMinWorkers) {
            return LimitingFactor::Strategy;
        }

        // If system capacity reduced the strategy recommendation
        if ($afterSystemCapacity < $strategyRecommendation) {
            return $capacityResult->limitingFactor; // Actually limited by CPU or memory
        }

        // Strategy recommendation was within capacity and config bounds
        return LimitingFactor::Strategy;
    }
}
