<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling;

/**
 * Where a workload's SLA signal came from.
 *
 * The distinction between the two oldest-age cases matters. OldestJobAge is
 * a real reading of the head of the queue: from a strategy that never measures
 * pickup times, or the head job's wait on a queue whose pickups have stalled.
 * OldestJobAgeFallback is a strategy that does measure them but had fewer
 * than `sla.min_samples` in the window this cycle — the age is standing in
 * for a percentile it could not compute, which is why a zero reading from it
 * is not taken as proof that a breach has ended. See
 * {@see SlaSignal::isInconclusive()}.
 */
enum SlaSignalSource: string
{
    /** The configured percentile (`sla.percentile`) of pickup times over `sla.window_seconds`. */
    case PickupPercentile = 'pickup_percentile';

    /**
     * The oldest pending job's age: from a strategy that does not measure
     * pickup times, or the head job's wait on a queue whose pickups have stalled.
     */
    case OldestJobAge = 'oldest_job_age';

    /** The oldest pending job's age, standing in for a percentile the window had too few samples for. */
    case OldestJobAgeFallback = 'oldest_job_age_fallback';
}
