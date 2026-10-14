<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Events;

use Cbox\LaravelQueueAutoscale\Scaling\SlaSignal;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event fired when a queue recovers from an SLA breach
 *
 * Fired once, when a workload that was breaching has an SLA signal back under
 * its target — the same signal SlaBreached was decided on. With a pickup-time
 * percentile that means the percentile over the SLA window has dropped below
 * the target, which can trail the backlog clearing by up to one window.
 *
 * Use this event to:
 * - Close alerts and incidents
 * - Log recovery metrics
 * - Notify stakeholders of resolution
 * - Calculate Mean Time To Recovery (MTTR)
 */
class SlaRecovered
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  int  $currentJobAge  The oldest pending job's age as the driver reported it. Always 0 on
     *                              drivers that cannot report it, such as Amazon SQS.
     * @param  SlaSignal|null  $slaSignal  The signal recovery was decided on. Null only on an event
     *                                     built by hand; the accessors then use $currentJobAge.
     */
    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly int $currentJobAge,
        public readonly int $slaTarget,
        public readonly int $pending,
        public readonly int $activeWorkers,
        public readonly ?SlaSignal $slaSignal = null,
    ) {}

    /**
     * The measured wait recovery was decided on, in seconds.
     */
    public function signalSeconds(): float
    {
        return $this->slaSignal->seconds ?? (float) $this->currentJobAge;
    }

    /**
     * Get the current margin below SLA (safety buffer), in whole seconds
     */
    public function marginSeconds(): int
    {
        return max(0, (int) floor($this->slaTarget - $this->signalSeconds()));
    }

    /**
     * Get the margin as percentage of SLA
     */
    public function marginPercentage(): float
    {
        if ($this->slaTarget === 0) {
            return 0.0;
        }

        return (($this->slaTarget - $this->signalSeconds()) / $this->slaTarget) * 100;
    }
}
