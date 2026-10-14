---
title: "Workers"
description: "The worker pool, spawner, terminator and the scaler that changes the pool"
weight: 50
---

## Workers

### `WorkerProcess`

A live `queue:work` subprocess wrapped with spawn metadata.

```php
namespace Cbox\LaravelQueueAutoscale\Workers;

class WorkerProcess
{
    public function __construct(
        public readonly Process $process,
        public readonly string $connection,
        public readonly string $queue,         // singular name OR comma-separated for group workers
        public readonly Carbon $spawnedAt,
        public readonly ?string $group = null,
    ) {}

    public function pid(): ?int;
    public function isRunning(): bool;
    public function isDead(): bool;
    public function isTerminating(): bool;
    public function markTerminationRequested(Carbon $requestedAt, int $timeoutSeconds): void;
    public function terminationDeadlinePassed(Carbon $now): bool;
    public function uptimeSeconds(): int;
    public function matches(string $connection, string $queue): bool;        // false for group workers
    public function matchesGroup(string $connection, string $group): bool;
    public function isGroupWorker(): bool;
    public function getIncrementalOutput(): string;
    public function getIncrementalErrorOutput(): string;
}
```

### `WorkerPool`

Collection wrapper over `WorkerProcess`, held in-process by the manager daemon. A web request cannot see it.

```php
namespace Cbox\LaravelQueueAutoscale\Workers;

class WorkerPool
{
    public function add(WorkerProcess $worker): void;
    public function addMany(Collection $workers): void;
    public function removeWorker(WorkerProcess $worker): void;
    public function remove(string $connection, string $queue, int $count): Collection;
    public function removeFromGroup(string $connection, string $group, int $count): Collection;

    public function count(string $connection, string $queue): int;
    public function countGroup(string $connection, string $group): int;
    public function totalCount(): int;          // running and not draining
    public function liveCount(string $connection, string $queue): int;    // running, draining included
    public function liveCountGroup(string $connection, string $group): int;
    public function liveTotalCount(): int;

    /** Running, non-draining workers spawned within the last $seconds (0 or less: none). */
    public function settling(int $seconds): array;   // list<WorkerProcess>
    public function settlingCount(int $seconds): int;

    public function queueCounts(): array;
    public function groupCounts(): array;

    public function all(): Collection;
    public function getDeadWorkers(): Collection;
    public function getTerminatingWorkers(): Collection;
    public function getByConnection(string $connection, string $queue): array;
    public function getTerminatable(string $connection, string $queue, int $count): Collection;
    public function getTerminatableFromGroup(string $connection, string $group, int $count): Collection;
    public function findByPid(int $pid): ?WorkerProcess;
    public function reset(): void;
}
```

There is no `getWorkerCount()` — use `count($connection, $queue)`, `countGroup()`, `totalCount()`, `queueCounts()` or `groupCounts()`.

### `WorkerScaler`

Everything that actually changes the pool. The manager decides what the pool should
look like; the scaler carries it out, and every method reports what it achieved rather
than what it was asked for — the spawner drops workers that fail to launch, and
`getTerminatable()` skips workers already draining.

```php
namespace Cbox\LaravelQueueAutoscale\Workers;

class WorkerScaler
{
    public function scaleUp(ScalingDecision $decision): void;
    public function scaleDown(ScalingDecision $decision): void;
    public function scaleUpGroup(GroupConfiguration $group, ScalingDecision $decision): void;
    public function scaleDownGroup(GroupConfiguration $group, ScalingDecision $decision): void;

    /** Trim a spawn request to the host's remaining `limits.max_total_workers` headroom. */
    public function clampToHostCeiling(int $requested): int;

    public function cleanupDeadWorkers(): void;
    public function enforceTerminationDeadlines(): void;

    /** @return list<string> */
    public function scalingLog(): array;
}
```

It shares the manager's `WorkerPool` rather than owning one, so counts read elsewhere
in a cycle and counts changed here are always the same pool.

`cleanupDeadWorkers()` removes every exited worker from the pool and reports each one: a
`Removed dead worker` log line (at `info` for a graceful exit, `warning` otherwise) and a
`WorkerExited` event. See [Event Handling](../basic-usage/event-handling.md#workerexited).

### `WorkerExitStatus`

How a reaped worker process ended. Built by `WorkerExitStatus::of(WorkerProcess $worker)` from the
worker's Symfony `Process`.

```php
namespace Cbox\LaravelQueueAutoscale\Workers;

readonly class WorkerExitStatus
{
    public function __construct(
        public ?int $exitCode,          // 128 + signal when a signal ended the process
        public ?int $termSignal,        // null when the process exited on its own
        public bool $terminationRequested,
        public int $uptimeSeconds,
    ) {}

    public static function of(WorkerProcess $worker): self;

    /** No signal, and exit code 0 or queue:work's memory-limit code (12). */
    public function isGraceful(): bool;

    /** @return array{exit_code: int|null, term_signal: int|null, termination_requested: bool, uptime_seconds: int} */
    public function toArray(): array;
}
```

`toArray()` is the log context of `Removed dead worker`. A signal the platform cannot report (PHP
built with `--enable-sigchild`) leaves `termSignal` null rather than throwing.

### `WorkerMemoryPeakReader`

Reads a worker's memory from `/proc/<pid>/status`, in MB. Both methods return null where `/proc`
is not available or the process is gone.

```php
namespace Cbox\LaravelQueueAutoscale\Workers;

class WorkerMemoryPeakReader
{
    public function __construct(string $procRoot = '/proc') {}

    /** VmHWM: peak resident memory since the process started, boot included. */
    public function peakMb(int $pid): ?float;

    /** VmRSS: resident memory right now. */
    public function residentMb(int $pid): ?float;
}
```

The manager uses `peakMb()` for the per-worker peak-memory floor and `residentMb()` for the memory
a booting worker already holds. See [Resource Constraints](../algorithms/resource-constraints.md).

### `WorkerTerminator`

```php
namespace Cbox\LaravelQueueAutoscale\Workers;

readonly class WorkerTerminator
{
    /**
     * SIGTERM every worker, wait one shared deadline (the longest shutdown timeout among them),
     * then SIGKILL whatever is left and wait up to a second for it to be reaped.
     *
     * @param  (callable(WorkerProcess): void)|null  $onTerminate  Called per worker as it is signalled.
     * @param  (callable(): void)|null  $whileWaiting  Called about once a second while waiting.
     * @return int Workers that had to be force-killed.
     */
    public function terminateAll(iterable $workers, ?callable $onTerminate = null, ?callable $whileWaiting = null): int;

    public function requestTermination(WorkerProcess $worker): bool;
    public function forceKillIfExpired(WorkerProcess $worker): bool;
    public function terminate(WorkerProcess $worker): bool;
}
```

`terminateAll()` marks each signalled worker as termination-requested, so its `WorkerExited` reports
`$terminationRequested` true. The manager's shutdown passes a `$whileWaiting` callback that keeps
forwarding worker output and reporting exits while workers drain.

### `WorkerSpawner`

Spawns `queue:work` subprocesses. The command it builds is exactly:

```bash
{PHP_BINARY} artisan queue:work {connection} \
    --queue={queue} \
    --tries={workers.tries} \
    --max-time={workers.max_time_seconds} \
    --timeout={workers.timeout_seconds} \
    --sleep={workers.sleep_seconds}
```

Every value comes from the queue's resolved `WorkerConfiguration`. `--memory` is never passed.

The only environment variables injected into a worker are:

```text
LARAVEL_AUTOSCALE_WORKER=true
AUTOSCALE_MANAGER_ID=<manager id>
AUTOSCALE_WORKER_GROUP=<group name>   # group workers only
```
