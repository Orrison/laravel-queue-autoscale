<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Contracts;

use Cbox\LaravelQueueAutoscale\Scaling\SlaSignal;
use Cbox\LaravelQueueAutoscale\Scaling\SlaSignalResolver;

/**
 * Opt-in for scaling strategies that report the SLA signal they scaled on.
 *
 * When the configured strategy implements this interface, the manager decides
 * SLA breach — SlaBreached, SlaRecovered, the console's breached/warning
 * status and the cluster leader's breach state — from the signal the strategy
 * reports, so the breach a listener hears about and the target the strategy
 * computed are always judged on the same number. The shipped HybridStrategy
 * implements it and reports the pickup-time percentile when the SLA window has
 * enough samples.
 *
 * A strategy that implements only {@see ScalingStrategyContract} is unaffected:
 * breach is decided from the oldest job's age, exactly as before this
 * interface existed. Pinned workloads, which never run the strategy, are
 * measured with {@see SlaSignalResolver}
 * when the configured strategy opts in here, and from the oldest job's age
 * otherwise.
 */
interface SlaSignalStrategy extends ScalingStrategyContract
{
    /**
     * The SLA signal behind the most recent calculateTargetWorkers() call, or
     * null when the strategy has none — the manager then falls back to the
     * oldest job's age.
     *
     * Read immediately after calculateTargetWorkers() for the same workload,
     * the same way getLastReason() and getLastPrediction() are.
     */
    public function getLastSlaSignal(): ?SlaSignal;
}
