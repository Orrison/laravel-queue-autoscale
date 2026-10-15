---
title: "Upgrading to v4"
description: "Every breaking change from v3, what it means and what to do, plus the behaviour changes between v4 minor releases"
weight: 91
---

# Upgrading to v4

Most applications upgrade by raising PHP to 8.4, renaming one configuration key and deleting another.
The list below is complete rather than short — everything that can break is here, including the parts
that will not affect you.

Start with the check command, which reads your configuration against the queues you actually have:

```bash
composer require cboxdk/laravel-queue-autoscale:^4.0
php artisan queue:autoscale:doctor
```

## Requirements

**PHP 8.4 is now the minimum**, and Laravel 11 is no longer supported. If you are on PHP 8.3 or
Laravel 11, upgrade those first — v3 runs on both and there is no rush to move.

| | v3 | v4 |
|---|---|---|
| PHP | 8.3, 8.4, 8.5 | **8.4, 8.5** |
| Laravel | 11, 12, 13 | **12, 13** |

## The worker timeout is now two settings

This is the change most likely to affect you, and the one most likely to have been quietly wrong
before.

In v3, `workers.timeout_seconds` was passed to `queue:work` as `--max-time` — the worker *process's*
lifetime. It was never a job timeout, despite reading like one. An operator setting `3600` believed
they had allowed hour-long jobs; what they had actually done was recycle the worker hourly while jobs
still died at Laravel's default timeout.

The two are now separate:

```php
'workers' => [
    'max_time_seconds' => 3600,  // --max-time: recycle the worker process this often
    'timeout_seconds' => 900,    // --timeout: how long a single job may run
],
```

**What to do:** rename `timeout_seconds` to `max_time_seconds` everywhere it appears in your config
and in any custom profile. Then decide what your job timeout should be — the new `timeout_seconds`
defaults to 900 seconds, and configuration will refuse a job timeout that is not shorter than the
process lifetime, because a job allowed to outlive its own worker can never finish.

## The global `workers` block is gone

v3 had two places to configure workers: a global `queue-autoscale.workers` block and a per-queue
`workers` key. Only the global one reached a spawned worker, so `tries`, `sleep_seconds` and
`shutdown_timeout_seconds` set on a profile were parsed, validated, and ignored.

The profile is now the only surface, and what a queue declares is what its workers run with.

**What to do:** delete the top-level `'workers' => [...]` block from `config/queue-autoscale.php`. If
it held values you relied on, move them into `sla_defaults` or into the profiles that need them —
they will now actually take effect, which may itself be a change in behaviour worth watching.

One key moved rather than being deleted: `workers.shutdown_timeout_seconds` is now
`manager.shutdown_grace_seconds`, and it is a *fallback* for workers whose queue configuration can no
longer be resolved — not a floor. A pool of fast queues no longer waits for the slowest global
setting.

## Metric names

The package publishes cluster metrics on two surfaces — the `clusterMetrics()` facade method and the
telemetry gauges — and they disagreed about five names. They now agree. If you scrape either, update
your queries:

| v3 | v4 |
|---|---|
| `queue_autoscale_cluster_hosts_recommended` | `queue_autoscale_cluster_recommended_hosts` |
| `queue_autoscale_cluster_workers_current` | `queue_autoscale_cluster_workers` |
| `queue_autoscale_cluster_workers_required` | `queue_autoscale_cluster_required_workers` |
| `queue_autoscale_cluster_capacity` | `queue_autoscale_cluster_worker_capacity` |
| `queue_autoscale_manager_workers` | `queue_autoscale_cluster_host_workers` |
| `queue_autoscale_manager_capacity` | `queue_autoscale_cluster_host_capacity` |
| `queue_autoscale_manager_cpu_percent` | `queue_autoscale_cluster_host_cpu_percent` |
| `queue_autoscale_manager_memory_percent` | `queue_autoscale_cluster_host_memory_percent` |

A dashboard querying an old name returns no data rather than an error, which looks identical to the
metric reading zero — so this is worth doing before you deploy, not after.

## The cluster store is behind a contract

`ClusterStore` now implements `ClusterStoreContract`, and everything in the package depends on the
contract. If you resolved `ClusterStore::class` from the container directly it still works, but
prefer the contract — and if you decorated or swapped the concrete class, bind
`ClusterStoreContract::class` instead.

## Deleted classes

- `Cbox\LaravelQueueAutoscale\Workers\ProcessHealthCheck` — worker liveness is checked inline in the
  evaluation cycle. Nothing referenced it.
- `Cbox\LaravelQueueAutoscale\Output\DataTransferObjects\JobActivity` — unused output DTO.

## New in v4, nothing to do

These are additive. Listed so you know they exist:

- **Queue patterns.** `queue-autoscale.queues` accepts glob keys, so `scrape-tenant-*` can govern
  every tenant queue at once. Exact names still win over patterns. See
  [Respect a Per-Tenant Connection Limit](../cookbook/per-tenant-connection-limits.md).
- **Queue names with dots now reach their config.** `config('queue-autoscale.queues.tenant.42')` split
  on the dots, so a queue literally named `tenant.42` — or any `.fifo` queue — silently ran on
  defaults. If you have such a queue, its configuration will start applying on upgrade. That is the
  fix working, but it is a behaviour change: check the doctor output.
- **`profile` alongside overrides.** A queue entry can name a profile and refine it, instead of
  restating every field.
- **`ConnectionLimitedProfile`** for queues whose parallelism is dictated by something downstream.
- **`queue:autoscale:doctor`** — see [Check Your Configuration](../basic-usage/configuration-check.md).
- **`src/Testing`** — fakes and assertions for testing your own configuration. See
  [Testing Your Configuration](../basic-usage/testing.md).
- **Pickup sampling.** Above 100 recorded pickups per second per worker process, a uniformly random
  subset is stored instead of every one. Enabled by default; it costs nothing in accuracy because the
  store only ever retained a bounded number of samples anyway. Disable with
  `QUEUE_AUTOSCALE_PICKUP_SAMPLING=false`.

## Checklist

- [ ] PHP 8.4+ and Laravel 12+
- [ ] `workers.timeout_seconds` renamed to `max_time_seconds`, and a job timeout chosen
- [ ] Top-level `'workers'` block deleted from the config
- [ ] `workers.shutdown_timeout_seconds` moved to `manager.shutdown_grace_seconds` if you set it globally
- [ ] Dashboards and alerts updated for the renamed metrics
- [ ] `php artisan queue:autoscale:doctor` run and its output read — it flags both of the
      configuration changes above if you miss them

## Upgrading within v4

Minor releases add no breaking API changes, but some change what the autoscaler measures, and so
how many workers it runs. Read the section for each minor you cross.

### 4.3 to 4.4

```bash
composer require cboxdk/laravel-queue-autoscale:^4.4
php artisan queue:autoscale:doctor
```

Then restart every `queue:autoscale` process. If you published the config file, the new keys fall
back to their defaults; copy them in only to change them.

**Requirements.** `cboxdk/laravel-queue-metrics` ^3.4.1 is required: it records SQS jobs under the
queue name and measures job duration from processing start. From 4.4.1, `cboxdk/system-metrics`
^3.1 is a declared dependency, which reads the cgroup working set and the real `oom_kill` counter on
cgroup v1.

**Memory capacity is computed differently.** 4.3 applied the host's used-memory percentage to
whatever total `SystemMetrics::limits()` reported, which mixed a container's limit with the host's
usage. 4.4 measures each scope (`configured`, `cgroup`, `host`) as a limit with its own usage,
applies `limits.max_memory_percent` to the most specific one, and caps the result at every other
scope's free memory. Expect different memory ceilings inside containers — usually lower, and
correct. Three more inputs can lower them:

- Workers spawned within `limits.worker_settle_seconds` (default 30) are charged the memory they have
  not grown into yet, so fewer workers are added in the cycle after a scale-up.
- On Linux, the per-worker estimate is floored at what the queue's live workers actually peak at
  (`VmHWM`), so a `worker_memory_mb_estimate` set below reality no longer holds.
- After the kernel OOM-kills processes in the manager's cgroup, capacity is held for
  `limits.oom_backoff_seconds` (default 300). Set it to `0` to turn this off.

From 4.4.1 the cgroup scope uses the working set, so a container that writes a lot of files is no
longer read as nearly full.

**Pod- and task-level memory limits need configuring.** If the manager runs in Kubernetes or ECS
with a memory limit only at the pod or task level, set `QUEUE_AUTOSCALE_MEMORY_LIMIT_MB` — the
container cannot see that limit. See [Kubernetes and Amazon ECS](../deployment/kubernetes-ecs.md).

**`memory_details` describes the chosen scope.** `total_memory_mb` and `current_memory_percent` now
describe the limit `max_memory_percent` applied to, not always the host. New keys are added
(`used_memory_mb`, `headroom_mb`, `memory_scope`, `memory_capped_by`, `settling_workers`,
`oom_backoff_ceiling`, `oom_backoff_until`) and `MemoryBreakdown` has matching properties. The
cluster heartbeat's `memory_*` fields and the `queue_autoscale_cluster_host_memory_percent` gauge
follow the same scope, so a dashboard of containers will show their own limits instead of their
nodes'. See [Scaling Decision](../api-reference/scaling-decision.md#the-details-array).

**Pickup time excludes delays and retries.** A delayed job is measured from when its delay ends
(Laravel 12.11+ writes the delay into the payload), only a job's first attempt is sampled, and
samples above `pickup_time.max_sample_seconds` (default 3600) are dropped. Measured pickup p95s on
queues with delayed or retried jobs will fall. If any queue's `sla.target_seconds` is 3600 or more,
raise the cap — the doctor warns about it.

**SQS queues start reporting.** On SQS, pickup samples and failure-fuse outcomes were recorded under
the queue URL and never reached the manager. From 4.4 they are recorded under the queue name, so:

- the hybrid strategy gets its p95 signal on SQS (with `stamp_pushed_at` and a Redis pickup store),
- the failure fuse can now trip on SQS queues, which it never could before,
- `excluded` patterns now match SQS queues.

See [Running on Amazon SQS](../deployment/amazon-sqs.md), including the new
`pickup_time.stamp_pushed_at` setting.

**Unworkable connections are skipped.** Queues on `sync`, `null`, `deferred` and `background`
connections are no longer discovered by the manager or listed by the doctor. A configuration rule
that only matched such a queue now reports as matching nothing.

**Worker exits are reported.** Every exit dispatches the new `WorkerExited` event and logs
`Removed dead worker` with `exit_code`, `term_signal`, `termination_requested` and
`uptime_seconds`. Clean exits now log at `info` instead of `warning`, so an alert on that warning
fires less often — and only for crashes and kills. A `WorkerExited` listener that throws outside
shutdown aborts the rest of that evaluation cycle, so keep listeners cheap. See
[Event Handling](../basic-usage/event-handling.md#workerexited).

#### 4.4 checklist

- [ ] `composer update` pulled `laravel-queue-metrics` 3.4.1+ and `system-metrics` 3.1+
- [ ] Every `queue:autoscale` process restarted
- [ ] `QUEUE_AUTOSCALE_MEMORY_LIMIT_MB` set where only a pod- or task-level limit exists
- [ ] Dashboards reading `total_memory_mb` / `current_memory_percent` checked against the new scope
- [ ] On SQS: `QUEUE_AUTOSCALE_STAMP_PUSHED_AT=true`, and a Redis pickup store on a single host
- [ ] `php artisan queue:autoscale:doctor` run, with no pickup-cap warning

### 4.4 to 4.5

```bash
composer require cboxdk/laravel-queue-autoscale:^4.5
```

Then restart every `queue:autoscale` process. Nothing in the config file changes.

**SLA breach is judged on the strategy's signal.** `SlaBreached`, `SlaRecovered`, the console's
`breached` / `warning` / `ok` status and the cluster leader's breach state used to be decided from
the oldest pending job's age alone, while the default `HybridStrategy` scaled on the p95 pickup
time. They are now decided from the same number the strategy scaled on: the `sla.percentile` of
pickup times over `sla.window_seconds` when the window holds at least `sla.min_samples`, the oldest
job's age otherwise. See [How breach is decided](../basic-usage/monitoring.md#how-breach-is-decided).
What that changes:

- **On Amazon SQS, breach events start firing.** SQS reports an oldest age of 0, so before 4.5 a
  queue there could never breach. With pickup samples recorded (`QUEUE_AUTOSCALE_STAMP_PUSHED_AT`
  and a Redis pickup store, as in 4.4), `SlaBreached` now fires when the p95 crosses the target.
  Expect alerts on SQS queues that were silently breaching.
- **On Redis, with samples, a p95 over the target opens a breach** even while the head of the queue
  is young, and an old head job alone does not — on Redis a delayed or retried job is aged from
  dispatch. A stall still breaches: since 4.6.0, when no pickup has been recorded for the target
  while jobs wait, the head job's wait counts (4.5.0 missed a stall until the fast samples aged out).
- **Recovery follows the window.** `SlaRecovered` fires when the percentile over the window drops
  under the target, which can trail the backlog clearing by up to one `sla.window_seconds`.
- **A short window does not end a breach while jobs wait.** On a driver that cannot report the
  oldest job's age (SQS), an open breach is held when the window falls below `sla.min_samples` and
  the oldest-age fallback reads 0 with jobs still pending. On Redis and the database a 0 is a real
  age and recovers as usual (since 4.6.0 — 4.5.0 held it there too, for at most a tick).

**Custom strategies are unaffected unless they opt in.** `ScalingStrategyContract` is unchanged. A
strategy that implements only it is judged on the oldest job's age, as before. To have breach
follow your strategy's own measurement, implement the new `SlaSignalStrategy` — see
[Custom Strategies](custom-strategies.md#reporting-an-sla-signal).

**Additive API.** Every addition is an optional trailing parameter, so existing calls and
subclasses keep working:

- `SlaBreached` and `SlaRecovered` take `?SlaSignal $slaSignal = null` and gain `signalSeconds()`.
  `oldestJobAge` / `currentJobAge` still carry the driver's raw age; `breachSeconds()`,
  `breachPercentage()`, `marginSeconds()` and `marginPercentage()` now use the signal when there is
  one — so on SQS they report real numbers instead of measuring against 0. Telemetry events carry
  `sla_signal_seconds` and `sla_signal_source`.
- `ScalingDecision` has `?SlaSignal $slaSignal`; `ScalingEngine` has `assessDemand()` and
  `measureSlaSignal()`; `EvaluatedWorkload` takes an optional `slaSignal` and gains `slaSignal()`.
- Cluster summary workloads carry `sla_signal_seconds`, `sla_signal_source` and `sla_status`, and
  `clusterMetrics()` exports `queue_autoscale_workload_sla_signal_seconds` and
  `queue_autoscale_workload_sla_breaching`. Read from a summary written by a 4.4 leader during a
  rolling upgrade, both fall back to the oldest job's age.
- Testing: `fakePickupTimes()`, `slaSignalFor()`, `assertBreachesSla()`, `assertWithinSla()`,
  `InMemoryPickupTimeStore` and `QueueMetricsFactory::onSqs()`. See [Testing](../basic-usage/testing.md).

#### 4.5 checklist

- [ ] Every `queue:autoscale` process restarted (a mixed cluster works, but a 4.4 leader still judges breach on oldest age)
- [ ] SLA alerts on SQS queues reviewed — they can fire now
- [ ] Alert messages built from `$event->oldestJobAge` switched to `$event->signalSeconds()`
- [ ] Custom strategies that measure pickup time: consider implementing `SlaSignalStrategy`

### 4.5 to 4.6

```bash
composer require cboxdk/laravel-queue-autoscale:^4.6
```

Restart every `queue:autoscale` process. Nothing in the config file changes. 4.6 corrects how 4.5
judged breach and adds a doctor check:

- **A stalled queue breaches again.** When no pickup has been recorded for `sla.target_seconds`
  while jobs wait, breach is judged on the head job's wait (the smaller of its age and the time since
  the last pickup), even though the window still holds fast pickups from before the stall. On a
  queue whose pickups keep arriving, an old head job — a delayed job or a retry, which Redis ages
  from dispatch — does not open a breach. Scaling is unchanged.
- **Discovered queues on a custom-named connection** (`redis-high`, `aws`) are recognised by their
  driver, not their connection name, for the rule below and in the cluster summary's `driver`.
- **A zero age is only "unknown" on SQS.** A breach is held through a short window only on drivers
  that cannot report the oldest job's age. On Redis and the database it recovers as usual.
- **Pinned queues in a cluster** fire one `SlaBreached` / `SlaRecovered` per cluster, from the
  leader, instead of one per manager. A newly elected leader carries open breaches over from the
  last published summary instead of announcing them again.
- **The doctor warns about SQS queues with no SLA signal**: an SQS queue while
  `pickup_time.stamp_pushed_at` is off, or while the pickup store resolves to the null store.
- **A `HybridStrategy` subclass** that answers some queues itself no longer reports the previous
  queue's SLA signal for them, and `HybridStrategy` now uses a container-bound
  `SlaSignalResolver`.
- **Verbose console output** prints the signal a breach was judged on, and `held (inconclusive)`
  while a breach is held through a short window.

#### 4.6 checklist

- [ ] Every `queue:autoscale` process restarted
- [ ] `php artisan queue:autoscale:doctor` run, with no SQS SLA-signal warning
