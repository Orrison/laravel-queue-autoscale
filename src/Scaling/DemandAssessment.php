<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling;

/**
 * A workload's cluster-wide demand and the SLA signal it was computed from.
 *
 * Returned together so the cluster leader judges breach on the number the
 * strategy actually scaled on, rather than measuring the pickup window a
 * second time and risking a different answer from the one the target came
 * from.
 *
 * Every field defaults, so `new DemandAssessment` is valid: readonly means it
 * cannot be mocked, and a public method returns it.
 */
readonly class DemandAssessment
{
    public function __construct(
        public int $targetWorkers = 0,
        public SlaSignal $slaSignal = new SlaSignal,
    ) {}
}
