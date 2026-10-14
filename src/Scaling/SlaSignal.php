<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling;

/**
 * The number a workload's SLA is judged against this cycle, and where it came
 * from.
 *
 * The scaling strategy and the breach state must read the same number. They
 * used to read different ones: HybridStrategy scaled on the p95 pickup time
 * while SlaBreached, SlaRecovered and the console's breached/warning status
 * were decided from the oldest job's age alone. On a driver that cannot report
 * that age — Amazon SQS reports 0 — a queue could therefore never enter breach
 * however far its measured pickup time had blown through the target.
 *
 * Every field defaults, so `new SlaSignal` is a valid "nothing waiting"
 * instance: readonly means it cannot be mocked, and public methods return it.
 */
readonly class SlaSignal
{
    /**
     * The share of the SLA target above which a workload that is not breaching
     * is reported as 'warning'.
     */
    public const WARNING_RATIO = 0.8;

    /**
     * @param  float  $seconds  The measured wait, in seconds.
     * @param  SlaSignalSource  $source  Which measurement produced it.
     * @param  int|null  $percentile  The percentile measured, when the source is PickupPercentile.
     */
    public function __construct(
        public float $seconds = 0.0,
        public SlaSignalSource $source = SlaSignalSource::OldestJobAge,
        public ?int $percentile = null,
    ) {}

    /**
     * The signal of a strategy that does not measure pickup times: the oldest
     * pending job's age, exactly as breach was decided before signals existed.
     */
    public static function fromOldestJobAge(int $oldestJobAge): self
    {
        return new self((float) $oldestJobAge, SlaSignalSource::OldestJobAge);
    }

    /**
     * The oldest job's age standing in for a pickup percentile that could not
     * be computed because the window held fewer than `sla.min_samples`.
     */
    public static function fromOldestJobAgeFallback(int $oldestJobAge): self
    {
        return new self((float) $oldestJobAge, SlaSignalSource::OldestJobAgeFallback);
    }

    /**
     * A pickup-time percentile measured over the SLA window.
     */
    public static function fromPickupPercentile(float $seconds, int $percentile): self
    {
        return new self($seconds, SlaSignalSource::PickupPercentile, $percentile);
    }

    /**
     * Whether the signal is at or over the target.
     *
     * Guarded on a non-zero reading because an empty queue reports an oldest
     * age of zero, which would otherwise read as "infinitely late" against any
     * target.
     */
    public function isBreaching(int $slaTargetSeconds): bool
    {
        return $this->seconds > 0 && $this->seconds >= $slaTargetSeconds;
    }

    /**
     * Whether the signal is over {@see WARNING_RATIO} of the target without
     * having reached it.
     */
    public function isWarning(int $slaTargetSeconds): bool
    {
        return ! $this->isBreaching($slaTargetSeconds)
            && $this->seconds > $slaTargetSeconds * self::WARNING_RATIO;
    }

    /**
     * Whether this reading says nothing about a breach that is already open.
     *
     * Only a fallback reading of zero while jobs are still pending qualifies.
     * That is what a driver unable to report the oldest job's age (SQS) shows
     * whenever the pickup window drops below `sla.min_samples` — which is
     * exactly what a stalled queue looks like, since a stall produces no
     * pickups. Letting it end the breach would emit SlaRecovered for a queue
     * that is further behind than ever, and SlaBreached again as soon as the
     * sample count crossed back, every time it crossed.
     *
     * A strategy that never measures pickup times does not get this treatment:
     * its oldest-age reading is all the evidence it has, and breach behaves
     * exactly as it did before signals existed.
     */
    public function isInconclusive(int $pending): bool
    {
        return $this->source === SlaSignalSource::OldestJobAgeFallback
            && $this->seconds <= 0
            && $pending > 0;
    }

    /**
     * Short operator-facing label, e.g. `p95 pickup=42.0s` or `oldest_age=42s`.
     */
    public function describe(): string
    {
        return match ($this->source) {
            SlaSignalSource::PickupPercentile => sprintf('p%d pickup=%.1fs', $this->percentile ?? 95, $this->seconds),
            SlaSignalSource::OldestJobAge,
            SlaSignalSource::OldestJobAgeFallback => sprintf('oldest_age=%ds', (int) round($this->seconds)),
        };
    }
}
