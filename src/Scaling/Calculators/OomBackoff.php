<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\Calculators;

use Carbon\CarbonInterface;
use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Illuminate\Support\Facades\Log;

/**
 * Holds this host's worker capacity after the kernel OOM-kills processes.
 *
 * An OOM kill frees memory, so capacity measured right after one reads as
 * room for more workers, and the manager spawns into the same limit that just
 * killed them. Only a rise in the cgroup's own OOM kill counter triggers the
 * hold: a worker dying from SIGKILL is not enough on its own, since queue:work
 * also SIGKILLs itself when a job exceeds --timeout.
 */
class OomBackoff
{
    private ?int $lastOomKillCount = null;

    private ?CarbonInterface $holdUntil = null;

    private ?int $ceiling = null;

    public function observe(?int $oomKillCount): void
    {
        if ($oomKillCount === null) {
            return;
        }

        $previous = $this->lastOomKillCount;
        $this->lastOomKillCount = $oomKillCount;

        // The first reading may include kills from before this manager started,
        // and a lower one means the counter was reset.
        if ($previous === null || $oomKillCount <= $previous) {
            return;
        }

        $seconds = AutoscaleConfiguration::oomBackoffSeconds();

        if ($seconds <= 0) {
            return;
        }

        $this->holdUntil = now()->addSeconds($seconds);
        $this->ceiling = null;

        Log::channel(AutoscaleConfiguration::logChannel())->warning(
            'Kernel OOM-killed processes on this host; holding worker capacity at its current count',
            ['oom_kills' => $oomKillCount - $previous, 'hold_seconds' => $seconds],
        );
    }

    /**
     * The worker ceiling while a hold is active, fixed at the worker count the
     * first time it is asked after the kill; null when no hold applies.
     */
    public function ceiling(int $currentWorkers): ?int
    {
        if ($this->holdUntil === null || now()->greaterThanOrEqualTo($this->holdUntil)) {
            $this->holdUntil = null;
            $this->ceiling = null;

            return null;
        }

        return $this->ceiling ??= max($currentWorkers, 0);
    }

    public function holdUntil(): ?CarbonInterface
    {
        return $this->holdUntil;
    }
}
