<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Workers;

use Illuminate\Queue\Worker;

/**
 * How a worker process ended, read from its reaped Symfony Process.
 */
readonly class WorkerExitStatus
{
    public function __construct(
        public ?int $exitCode,
        public ?int $termSignal,
        public bool $terminationRequested,
        public int $uptimeSeconds,
    ) {}

    public static function of(WorkerProcess $worker): self
    {
        $process = $worker->process;
        $termSignal = null;

        try {
            if ($process->hasBeenSignaled()) {
                $termSignal = $process->getTermSignal();
            }
        } catch (\Throwable) {
            // getTermSignal() throws on PHP built with --enable-sigchild when the signal is unknown.
        }

        return new self(
            exitCode: $process->getExitCode(),
            termSignal: $termSignal,
            terminationRequested: $worker->isTerminating(),
            uptimeSeconds: $worker->uptimeSeconds(),
        );
    }

    /**
     * Whether queue:work stopped itself cleanly: on request, at --max-time or
     * --max-jobs, on a restart signal, or at its --memory limit. Anything
     * else (a signal, a fatal error) is how a worker that was killed or
     * crashed shows up.
     */
    public function isGraceful(): bool
    {
        return $this->termSignal === null
            && in_array($this->exitCode, [Worker::EXIT_SUCCESS, Worker::EXIT_MEMORY_LIMIT], true);
    }

    /**
     * @return array{exit_code: int|null, term_signal: int|null, termination_requested: bool, uptime_seconds: int}
     */
    public function toArray(): array
    {
        return [
            'exit_code' => $this->exitCode,
            'term_signal' => $this->termSignal,
            'termination_requested' => $this->terminationRequested,
            'uptime_seconds' => $this->uptimeSeconds,
        ];
    }
}
