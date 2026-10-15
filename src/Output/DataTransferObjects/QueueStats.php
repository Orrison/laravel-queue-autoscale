<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Output\DataTransferObjects;

use Cbox\LaravelQueueAutoscale\Scaling\SlaSignal;

readonly class QueueStats
{
    /**
     * @param  string  $slaStatus  'ok', 'warning', 'breached'
     * @param  SlaSignal|null  $slaSignal  The reading the status was judged on; null on stats built by hand
     */
    public function __construct(
        public string $connection,
        public string $queue,
        public int $depth,
        public int $pending,
        public float $throughputPerMinute,
        public int $oldestJobAge,
        public int $slaTarget,
        public string $slaStatus,
        public int $activeWorkers,
        public int $targetWorkers,
        public int $reserved = 0,
        public int $scheduled = 0,
        public ?SlaSignal $slaSignal = null,
    ) {}

    /**
     * The reading the SLA status was judged on, as the console prints it:
     * `p95 pickup=42.0s`, or `oldest_age=42s` when there is no signal.
     */
    public function slaReading(): string
    {
        return $this->slaSignal?->describe() ?? sprintf('oldest_age=%ds', $this->oldestJobAge);
    }

    /**
     * Whether the workload is reported as breached while its reading is
     * under the target: a breach held open through an inconclusive reading
     * (see SlaSignal::isInconclusive()).
     */
    public function isBreachHeld(): bool
    {
        return $this->isBreaching()
            && $this->slaSignal !== null
            && ! $this->slaSignal->isBreaching($this->slaTarget);
    }

    public function key(): string
    {
        return "{$this->connection}:{$this->queue}";
    }

    public function isBreaching(): bool
    {
        return $this->slaStatus === 'breached';
    }

    public function isWarning(): bool
    {
        return $this->slaStatus === 'warning';
    }
}
