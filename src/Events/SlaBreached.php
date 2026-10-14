<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Events;

use Cbox\LaravelQueueAutoscale\Scaling\SlaSignal;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event fired when an SLA breach is actively occurring
 *
 * Fired once, when a workload's SLA signal reaches its target: the pickup-time
 * percentile over the SLA window when the configured strategy measures one and
 * the window holds enough samples, otherwise the oldest pending job's age.
 * This indicates an actual breach in progress, not just a prediction.
 *
 * Use this event to:
 * - Trigger alerts to operations teams
 * - Log critical SLA violations
 * - Escalate to incident management systems
 * - Track SLA compliance metrics
 */
class SlaBreached
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  int  $oldestJobAge  The oldest pending job's age as the driver reported it. Always 0 on
     *                             drivers that cannot report it, such as Amazon SQS.
     * @param  SlaSignal|null  $slaSignal  The signal the breach was decided on. Null only on an event
     *                                     built by hand; the accessors then use $oldestJobAge.
     */
    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly int $oldestJobAge,
        public readonly int $slaTarget,
        public readonly int $pending,
        public readonly int $activeWorkers,
        public readonly ?SlaSignal $slaSignal = null,
    ) {}

    /**
     * The measured wait the breach was decided on, in seconds.
     */
    public function signalSeconds(): float
    {
        return $this->slaSignal->seconds ?? (float) $this->oldestJobAge;
    }

    /**
     * Get the breach severity (how far over SLA), in whole seconds
     */
    public function breachSeconds(): int
    {
        return max(0, (int) ceil($this->signalSeconds() - $this->slaTarget));
    }

    /**
     * Get the breach percentage (how far over SLA as percentage)
     */
    public function breachPercentage(): float
    {
        if ($this->slaTarget === 0) {
            return 0.0;
        }

        return (($this->signalSeconds() - $this->slaTarget) / $this->slaTarget) * 100;
    }
}
