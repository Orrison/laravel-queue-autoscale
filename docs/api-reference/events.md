---
title: "Events"
description: "Every event the manager dispatches and the payload each carries"
weight: 40
---

## Events

All live in `Cbox\LaravelQueueAutoscale\Events`. See [Event Handling](../basic-usage/event-handling.md) for listener patterns.

| Event | Fires | Payload |
|---|---|---|
| `ScalingDecisionMade` | Every cycle | `$decision` |
| `SlaBreachPredicted` | Every cycle during risk | `$decision` |
| `SlaBreached` | Once per state transition | `$connection`, `$queue`, `$oldestJobAge`, `$slaTarget`, `$pending`, `$activeWorkers`, `$slaSignal` |
| `SlaRecovered` | Once per state transition | `$connection`, `$queue`, `$currentJobAge`, `$slaTarget`, `$pending`, `$activeWorkers`, `$slaSignal` |
| `WorkersScaled` | Per spawn/terminate action | `$connection`, `$queue`, `$from`, `$to`, `$action`, `$reason` |
| `WorkerExited` | Once per worker process the manager finds has ended, including during shutdown (since 4.4) | `$connection`, `$queue`, `$group`, `$pid`, `$exitCode`, `$termSignal`, `$terminationRequested`, `$uptimeSeconds`, `$graceful` |
| `ClusterScalingSignalUpdated` | When the leader publishes a new host scaling signal | `$clusterId`, `$leaderId`, `$currentHosts`, `$recommendedHosts`, `$currentCapacity`, `$requiredWorkers`, `$action`, `$reason` |
| `AutoscaleManagerStarted` | When a manager process starts | `$managerId`, `$host`, `$clusterEnabled`, `$clusterId`, `$intervalSeconds`, `$startedAt`, `$packageVersion` |
| `AutoscaleManagerStopped` | When a manager process stops | `$managerId`, `$host`, `$clusterEnabled`, `$clusterId`, `$startedAt`, `$stoppedAt`, `$reason`, `$workerCount`, `$packageVersion` |
| `ClusterLeaderChanged` | When a manager observes the cluster leader change | `$clusterId`, `$previousLeaderId`, `$currentLeaderId`, `$observedByManagerId`, `$changedAt` |
| `ClusterManagerPresenceChanged` | When the leader observes managers join/leave the active set | `$clusterId`, `$managerIds`, `$addedManagerIds`, `$removedManagerIds`, `$leaderId`, `$observedByManagerId`, `$observedAt` |
| `ClusterSummaryPublished` | When the leader publishes a fresh cluster summary | `$clusterId`, `$leaderId`, `$summary`, `$publishedAt` |

### `SlaBreached` / `SlaRecovered` fields

| Property | Type | Meaning |
|---|---|---|
| `$connection` | `string` | Queue connection |
| `$queue` | `string` | Queue name, or the group name for a group |
| `$oldestJobAge` / `$currentJobAge` | `int` | The oldest pending job's age as the driver reported it. Always `0` on Amazon SQS |
| `$slaTarget` | `int` | `sla.target_seconds` |
| `$pending` | `int` | Pending jobs when the transition was observed |
| `$activeWorkers` | `int` | Workers on the workload when the transition was observed |
| `$slaSignal` | `?SlaSignal` | The number the transition was decided on (since 4.5). `null` only on an event constructed by hand |

`signalSeconds()` returns `$slaSignal->seconds`, or the job age when there is no signal.
`breachSeconds()` (rounded up), `breachPercentage()`, `marginSeconds()` (rounded down) and
`marginPercentage()` are computed from it.

`SlaSignal` (`Cbox\LaravelQueueAutoscale\Scaling\SlaSignal`, readonly):

| Member | Meaning |
|---|---|
| `float $seconds` | The measured wait |
| `SlaSignalSource $source` | `PickupPercentile` (`'pickup_percentile'`), `OldestJobAgeFallback` (`'oldest_job_age_fallback'`, too few samples) or `OldestJobAge` (`'oldest_job_age'`, a strategy that measures no pickups, or the head job's wait on a stalled queue) |
| `?int $percentile` | The configured `sla.percentile` when the source is `PickupPercentile` |
| `?float $secondsSinceLastPickup` | How long ago the newest pickup in the window was, when the source is `PickupPercentile` |
| `isBreaching(int $target): bool` | `seconds > 0 && seconds >= $target` |
| `isWarning(int $target): bool` | Over 80% of the target without breaching |
| `isInconclusive(int $pending, bool $driverReportsOldestJobAge = false): bool` | A fallback reading of 0 with jobs pending, on a driver that cannot report the oldest job's age; holds an open breach |
| `withStallEvidence(int $oldestJobAge, int $pending, int $slaTargetSeconds): SlaSignal` | The head job's wait when no pickup has been recorded for the target while jobs wait; otherwise the signal unchanged |
| `SlaSignal::driverReportsOldestJobAge(string $driver): bool` | `true` for `redis` and `database` |
| `describe(): string` | `p95 pickup=42.0s` or `oldest_age=42s` |

See [How breach is decided](../basic-usage/monitoring.md#how-breach-is-decided).

### `WorkerExited` fields

| Property | Type | Meaning |
|---|---|---|
| `$connection` | `string` | Queue connection |
| `$queue` | `string` | Queue name, or the comma-separated queue list of a group worker |
| `$group` | `?string` | Group name for a group worker, else `null` |
| `$pid` | `?int` | Process ID |
| `$exitCode` | `?int` | Exit code; `128 + signal` when a signal ended the process (`137` for SIGKILL) |
| `$termSignal` | `?int` | Terminating signal, or `null` when the process exited on its own |
| `$terminationRequested` | `bool` | The manager had asked the worker to stop |
| `$uptimeSeconds` | `int` | Seconds from spawn until the exit was noticed |
| `$graceful` | `bool` | No signal, and exit code `0` or `12` (`queue:work`'s memory-limit exit) |

See [Event Handling](../basic-usage/event-handling.md#workerexited) for how common exits read.

## Alerting

### `AlertRateLimiter`

Cache-lock-based cooldown helper. Used internally by `BreachNotificationPolicy` and recommended for custom alert listeners.

```php
namespace Cbox\LaravelQueueAutoscale\Alerting;

readonly class AlertRateLimiter
{
    public function __construct(public int $cooldownSeconds = 300) {}

    public function allow(string $key): bool;  // true = proceed, false = still in cooldown
}
```

Resolved from the container with cooldown from `queue-autoscale.alerting.cooldown_seconds` (env var: `QUEUE_AUTOSCALE_ALERT_COOLDOWN`).
