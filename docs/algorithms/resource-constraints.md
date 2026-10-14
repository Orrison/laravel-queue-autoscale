---
title: "Resource Constraints"
description: "How CPU and memory capacity, per-worker resource estimates and config bounds cap the worker target"
weight: 54
---

# Resource Constraints

The strategy answers "how many workers does this queue need?". Resource constraints answer "how
many can this host actually run?". `ScalingEngine` applies the second to the first.

Four source files do most of the work:

- `src/Scaling/Calculators/CapacityCalculator.php` — CPU and memory capacity from live system metrics
- `src/Scaling/Calculators/OomBackoff.php` — the capacity hold after the kernel OOM-kills processes
- `src/Scaling/ResourceEstimateResolver.php` — per-worker CPU/memory estimates, per queue
- `src/Scaling/DTOs/CapacityCalculationResult.php` — the breakdown handed back to the decision

There is no cost, budget or spend constraint in this package.

## Per-worker estimates

Capacity math needs a cost per worker. `ResourceEstimateResolver::resolve(connection, queue)`
resolves CPU and memory **independently**, each through the same three-source chain:

| Precedence | Source | Where it comes from |
|---|---|---|
| 1 | `EstimateSource::Measured` | Runtime measurements fed in via `setMeasured()` / `setMeasuredCpu()` / `setMeasuredMemory()` |
| 2 | `EstimateSource::Config` | The queue's `resources` key in `queue-autoscale.queues.<queue>` |
| 3 | `EstimateSource::Default` | `limits.worker_cpu_core_estimate` / `limits.worker_memory_mb_estimate` |

A queue can have measured CPU and config memory at the same time; the dimensions never borrow each
other's source.

```php
// config/queue-autoscale.php
'queues' => [
    'video-encode' => [
        'resources' => [
            'cpu_cores' => 1.5,    // cores per worker
            'memory_mb' => 2048,   // MB per worker
        ],
    ],
],
```

Only numeric values are accepted (`AutoscaleConfiguration::queueResources()` filters to `int|float`),
and the resolver floors the result at `0.01` cores and `16.0` MB.

The resulting `ResourceEstimate` carries both values, both `EstimateSource` enums, and the sample
counts behind any measured value — which is what makes `queue:autoscale:debug` able to say *why* a
number was used.

### The peak-memory floor

The per-job memory that `laravel-queue-metrics` records is sampled while a job runs, so it misses a
worker's boot peak: loading the framework and filling OPcache before the first job. That boot peak
is when a host packed to its limit gets OOM-killed.

So on Linux, at the start of every cycle, the manager reads each live worker's `VmHWM` (its peak
resident memory since it started, from `/proc/<pid>/status`) and groups the readings by workload:
`connection:queue`, or `connection:group` for group workers. The memory estimate is then floored at
what those workers typically peak at:

| Live workers sampled | Statistic | Sorted index used |
|---|---|---|
| 1 to 9 | Lower median | `intdiv(n - 1, 2)` |
| 10 or more | Nearest-rank 90th percentile | `ceil(0.9 × n) - 1` |

`VmHWM` covers a worker's whole life, so one worker that once ran an unusually heavy job stays an
outlier for as long as it lives. Below ten samples the nearest-rank p90 is simply the maximum, so
the lower median is used instead: with two or more samples it is never the highest one, so a single
outlier cannot set the cost of every other worker on the queue. The boot peak this floor exists to
catch is shared by every worker, so the typical worker already carries it.

The floor only ever raises an estimate. When it does, the estimate's source becomes
`EstimateSource::Measured` and its sample count is the number of workers sampled. A workload with no
live workers has no floor, and where `/proc` is not available (macOS, BSD, Windows) nothing changes.

The per-host capacity a cluster manager reports in its heartbeat uses
`ResourceEstimateResolver::hostEstimate()`: the global default estimate, floored the same way across
all of the host's live workers.

## Capacity calculation

`CapacityCalculator::calculateMaxWorkers(int $currentWorkers, ResourceEstimate $estimate)` returns a
`CapacityCalculationResult`. `$currentWorkers` is the **total workers already running on this host
across all queues**, and it appears in both formulas:

```text
availableCpuPercent  = max(limits.max_cpu_percent - currentCpuPercent, 0)
usableCores          = max(totalCores - limits.reserve_cpu_cores, 0)
availableCoreEquiv   = usableCores * (availableCpuPercent / 100)
maxWorkersByCpu      = currentWorkers + floor(availableCoreEquiv / max(cpuCoresPerWorker, 0.01))

workerMemoryMb       = max(memoryMbPerWorker, 1.0)
primary              = the first scope measured of: configured, cgroup, host (else fallback)
scopeHeadroomMb      = primary.limitMb * (limits.max_memory_percent / 100) - primary.usedMb
scopeHeadroomMb      = min(scopeHeadroomMb, free memory (limitMb - usedMb) of every other scope)
settlingChargeMb     = sum over settling workers of max(workerMemoryMb - residentMbAtRead, 0)
headroomMb           = max(max(scopeHeadroomMb, 0) - settlingChargeMb, 0)
maxWorkersByMemory   = currentWorkers + floor(headroomMb / workerMemoryMb)

finalMaxWorkers      = max(min(maxWorkersByCpu, maxWorkersByMemory), 0)
finalMaxWorkers      = min(finalMaxWorkers, oomBackoffCeiling)    -- only while an OOM hold is active
```

The `currentWorkers +` term is not optional. The usage measured is *current* usage, which already
includes the running workers — so the division yields how many workers can be **added**, and the
running ones must be added back to get a total ceiling. Dropping the term would make the autoscaler
terminate workers it had just decided were affordable.

`limitingFactor` is `'cpu'` when `maxWorkersByCpu < maxWorkersByMemory`, `'memory'` in the reverse
case, and `'balanced'` when they are equal. An active OOM hold that lowers `finalMaxWorkers` also
sets it to `'memory'`.

### Which memory limit applies

Memory is read in up to three scopes, each pairing a limit with usage measured against that same
limit:

| Scope | Limit | Used | Measured when |
|---|---|---|---|
| `host` | `MemTotal` | `MemTotal - MemAvailable` | the host's memory can be read (Linux `/proc/meminfo`, or the platform equivalent) |
| `cgroup` | the container's `memory.max` (v2) / `memory.limit_in_bytes` (v1) | the cgroup's working set: usage minus inactive file cache from `memory.stat` (`inactive_file` on v2, `total_inactive_file` on v1); raw usage when that cannot be read | the container has its own memory limit (a limit of zero counts as none) |
| `configured` | `limits.memory_limit_mb` | the cgroup's working set (or raw usage) when the container reports it, else the host's usage | `memory_limit_mb` is set to a positive number |
| `fallback` | the memory `SystemMetrics::limits()` reports, or 4096 MB if it reports none | half the limit | none of the above could be read |

`max_memory_percent` applies to the most specific scope (`configured`, else `cgroup`, else `host`).
Every other scope caps the headroom at what it has free, so a container on a crowded node cannot
claim memory the node does not have. When one does, `memory_details.memory_capped_by` names it.

`MemAvailable` already excludes reclaimable page cache, and the cgroup working set discounts it the
same way, which is how Kubernetes measures a container's memory. Raw cgroup usage counts page cache
the kernel reclaims under pressure, so a container writing log files would drift toward its limit,
read as nearly full, and stop scaling up. Releases before 4.4.1 used raw usage.

A process cannot see a memory limit set only on a parent cgroup, such as a Kubernetes pod-level
limit or an Amazon ECS task-level limit with no container limit. There the host scope applies, and
in a VM-backed container it can describe far more memory than the container may use. Give the
container its own limit, or set `limits.memory_limit_mb` (`QUEUE_AUTOSCALE_MEMORY_LIMIT_MB`). See
[Kubernetes and Amazon ECS](../deployment/kubernetes-ecs.md).

`total_memory_mb`, `used_memory_mb` and `current_memory_percent` in `memory_details` describe the
primary scope, not necessarily the host.

### Workers that are still booting

A worker grows to its footprint while it boots (loading the framework, filling OPcache), so its
memory does not show as used straight away. Without a correction, a batch spawned in one cycle
reads as free memory in the next, and the next batch overshoots.

A worker is *settling* while it is running, not being terminated, and was spawned within
`limits.worker_settle_seconds` (default 30; `0` turns the charge off). Each settling worker is
charged the part of the per-worker estimate it does not hold yet: the estimate minus its resident
memory (`VmRSS`) at the moment usage was measured, because that much is already in the measured
usage. A worker spawned after usage was read, or whose resident memory cannot be read (no `/proc`),
is charged the full estimate. The settling list is re-read on every call, so workers spawned earlier
in the same cycle are charged even though the cached usage predates them, and it is never longer
than `$currentWorkers`.

`memory_details.settling_workers` is how many workers were charged; `headroom_mb` is the headroom
after the charge.

### After OOM kills

When the kernel OOM-kills processes in the manager's cgroup, the memory they held is freed, so the
next capacity reading looks like room for more workers and the manager would spawn straight back
into the limit that killed them.

`CapacityCalculator` passes the cgroup's OOM kill counter (`SystemMetrics::container()->oomKillCount`,
from `memory.events` on cgroup v2 and `memory.oom_control` on cgroup v1) to `OomBackoff` on every
metrics refresh:

- The first reading never triggers a hold: it may count kills from before this manager started.
- A reading lower than the previous one is a counter reset, and does not trigger a hold.
- A rise starts a hold for `limits.oom_backoff_seconds` (default 300, env
  `QUEUE_AUTOSCALE_OOM_BACKOFF_SECONDS`; `0` disables) and logs
  `Kernel OOM-killed processes on this host; holding worker capacity at its current count` at
  `warning`, with the number of kills and the hold length.
- The ceiling is fixed at the host's total worker count the first time capacity is calculated after
  the kill, and stays there for the rest of the hold: workers that die later do not lower it, and
  new ones cannot raise it. The manager supplies the host total, so a per-queue caller such as
  `NoScaleDownPolicy` cannot fix the ceiling at one queue's worker count.
- A further rise during a hold restarts the timer, and the ceiling is fixed again at the next
  calculation.
- When the hold expires the ceiling is cleared and normal capacity applies.

While a hold is active, `finalMaxWorkers` and `maxWorkersByMemory` are lowered to the ceiling (never
raised to it), and `memory_details` carries `oom_backoff_ceiling` and `oom_backoff_until` (ISO 8601).

Only the counter triggers this. A worker dying from SIGKILL is not enough on its own, because
`queue:work` also SIGKILLs itself when a job exceeds `--timeout`. Where the counter is not available
(outside Linux cgroups, or on a cgroup v1 kernel older than 4.13) nothing changes.

### System metrics and caching

Live values come from `SystemMetrics`: `limits()` for total cores, `cpu()` for CPU counters, and
`memory()` and `container()` for memory and the OOM kill counter. CPU usage is the difference
between this tick's counters and the previous tick's, so the calculator never sleeps to sample it;
the first tick of a process has nothing to diff against and assumes `50.0%`. Results are cached for
`CACHE_TTL_SECONDS = 4.0` — one measurement per evaluation tick, not one per queue.
`invalidateCache()` forces a fresh read. Settling workers are the one input read on every call.

Degradation is layered:

- `SystemMetrics::limits()` fails — the whole calculation is abandoned and a fixed fallback is
  returned: `maxWorkersByCpu: 5`, `maxWorkersByMemory: 5`, `maxWorkersByConfig: PHP_INT_MAX`,
  `finalMaxWorkers: 5`, `limitingFactor: 'system_metrics_unavailable'`. Its `details` carry only
  `cpu_explanation`, `memory_explanation` and `error`.
- Only the CPU read fails — the last known value is kept, or `50.0%` if there is none.
- No memory scope can be read — the `fallback` scope above applies.

### Configuration

```php
// config/queue-autoscale.php
'limits' => [
    'max_cpu_percent' => 85,            // CPU headroom ceiling
    'max_memory_percent' => 85,         // ceiling on the primary memory scope's limit
    'worker_memory_mb_estimate' => 128, // default MB per worker
    'worker_cpu_core_estimate' => 0.2,  // default cores per worker
    'reserve_cpu_cores' => 0.2,         // cores held back for the OS and the manager
    'memory_limit_mb' => env('QUEUE_AUTOSCALE_MEMORY_LIMIT_MB'),               // a limit this process cannot see
    'worker_settle_seconds' => 30,      // how long a new worker is charged memory it has not grown into
    'oom_backoff_seconds' => env('QUEUE_AUTOSCALE_OOM_BACKOFF_SECONDS', 300), // hold after an OOM kill; 0 disables
],
```

Per-queue bounds are separate and live in the queue's profile or override:

```php
'queues' => [
    'payments' => [
        'workers' => [
            'min' => 1,
            'max' => 20,
        ],
    ],
],
```

## How the engine applies capacity

`ScalingEngine::evaluate()`, in order:

```text
1. targetWorkers = strategy.calculateTargetWorkers(metrics, config)

2. effectiveTotalWorkers = max(totalPoolWorkers, currentWorkers)
   estimate       = resolver.resolve(connection, queue)
   capacityResult = capacity.calculateMaxWorkers(effectiveTotalWorkers, estimate)

3. otherQueuesWorkers   = effectiveTotalWorkers - currentWorkers
   availableForThisQueue = max(capacityResult.finalMaxWorkers - otherQueuesWorkers, 0)
   targetWorkers = min(targetWorkers, availableForThisQueue)

4. targetWorkers = max(targetWorkers, workers.min)
   targetWorkers = min(targetWorkers, workers.max)

5. fuseCeiling = fuse.evaluate(config).workerCeiling(workers.min)
   if fuseCeiling !== null:
       targetWorkers = min(targetWorkers, max(0, min(fuseCeiling, workers.max)))
```

Step 3 is why one busy queue cannot claim the whole host: system capacity is host-wide, so each
queue's ceiling is the host ceiling minus what every other queue is already using.

Note the ordering of steps 3 and 4: `workers.min` is applied **after** the capacity cut, so a
configured minimum wins over a capacity shortfall. A queue with `min: 5` keeps five workers even on
a saturated host.

`evaluateDemand()` — used by the cluster leader — deliberately skips step 2 and 3 entirely. Local
CPU and memory say nothing about cluster-wide demand; per-host capacity is enforced when each
manager executes.

## The result object

`CapacityCalculationResult` is attached to every `ScalingDecision` as `$decision->capacity`:

| Property | Meaning |
|---|---|
| `maxWorkersByCpu` | CPU-derived ceiling (includes `currentWorkers`) |
| `maxWorkersByMemory` | Memory-derived ceiling (includes `currentWorkers`) |
| `maxWorkersByConfig` | `PHP_INT_MAX` from the calculator; overwritten with `workers.max` by the engine |
| `finalMaxWorkers` | From the calculator, the min of CPU and memory; on the engine's copy, the final target |
| `limitingFactor` | A `LimitingFactor` enum: `cpu`, `memory`, `balanced`, `system_metrics_unavailable`, or after the engine `config`, `strategy`, `fuse` |
| `details` | `cpu_explanation`, `memory_explanation`, and nested `cpu_details` / `memory_details`. Every key is listed in [Scaling Decision](../api-reference/scaling-decision.md#the-details-array). Prefer the typed `cpuBreakdown()` / `memoryBreakdown()` accessors over indexing into it |

Helpers: `isCpuLimited()`, `isMemoryLimited()`, `isConfigLimited()`, `getSummary()`,
`getFormattedDetails()`. The `-vvv` output of `queue:autoscale` prints `getFormattedDetails()`
verbatim.

The engine's `determineFinalLimitingFactor()` resolves the post-clamp label: `'config'` when
`workers.max` capped the target, `'strategy'` when `workers.min` raised it or nothing constrained
it, and the capacity result's own factor when system capacity cut the strategy recommendation.
A tripped fuse overrides all of them with `'fuse'`.

## Worked examples

All examples use the default `max_cpu_percent` and `max_memory_percent` of 85.

### Example 1: CPU is the limiting factor

A VM with no container limit, so the `host` scope is primary:

| Input | Value |
|---|---|
| Total cores | 8 |
| Current CPU | 60% |
| `MemTotal` | 16384 MB |
| `MemTotal - MemAvailable` | 8192 MB |
| Workers on host | 6 |
| Estimate | 0.2 cores, 128 MB per worker |

```text
availableCpuPercent = max(85 - 60, 0)      = 25
usableCores         = max(8 - 0.2, 0)      = 7.8
availableCoreEquiv  = 7.8 * 0.25           = 1.95
maxWorkersByCpu     = 6 + floor(1.95 / 0.2) = 6 + 9 = 15

scopeHeadroomMb     = 16384 * 0.85 - 8192  = 5734.4   (no other scope to cap it)
headroomMb          = 5734.4                          (no settling workers)
maxWorkersByMemory  = 6 + floor(5734.4 / 128) = 6 + 44 = 50

finalMaxWorkers     = min(15, 50) = 15                (limitingFactor: cpu)
```

### Example 2: this queue's share

Continuing from Example 1, with this queue running 4 of the 6 workers and the strategy asking for
25:

```text
otherQueuesWorkers    = 6 - 4 = 2
availableForThisQueue = max(15 - 2, 0) = 13
targetWorkers         = min(25, 13) = 13
```

Then `workers.min`/`workers.max` and the fuse apply.

### Example 3: a memory-heavy queue

Same host, but the queue declares `'resources' => ['cpu_cores' => 0.5, 'memory_mb' => 2048]`:

```text
maxWorkersByCpu    = 6 + floor(1.95 / 0.5)     = 6 + 3 = 9
maxWorkersByMemory = 6 + floor(5734.4 / 2048)  = 6 + 2 = 8

finalMaxWorkers    = min(9, 8) = 8                     (limitingFactor: memory)
```

The per-queue estimate changed the ceiling from 15 to 8 without any global config change.

### Example 4: saturated host

```text
Current CPU 90% (above max_cpu_percent 85)

availableCpuPercent = max(85 - 90, 0) = 0
availableCoreEquiv  = 0
maxWorkersByCpu     = 6 + 0 = 6
finalMaxWorkers     = 6
```

No new workers anywhere on the host; existing ones are not forcibly reclaimed by this calculation.

### Example 5: a container with its own limit, and the working set

A container limited to 2048 MB, on a node with 16384 MB. Its cgroup reports 1500 MB of raw usage, of
which 400 MB is inactive file cache (log files it has written). Two workers run; the estimate is
128 MB.

```text
cgroup scope: limit 2048, used = 1500 - 400 = 1100      (working set)
host scope:   limit 16384, used 15000, free 1384

primary             = cgroup
scopeHeadroomMb     = 2048 * 0.85 - 1100 = 640.8
host free 1384 >= 640.8, so not capped
maxWorkersByMemory  = 2 + floor(640.8 / 128) = 2 + 5 = 7
```

Measured by raw usage, as releases before 4.4.1 did, the same container reads
`1740.8 - 1500 = 240.8` MB of headroom, room for one more worker, and every log line it writes
pushes that toward zero.

Now let the node get crowded: other pods push the host's usage to 15800 MB.

```text
host free           = 16384 - 15800 = 584   < 640.8
scopeHeadroomMb     = 584                   (memory_capped_by: host)
maxWorkersByMemory  = 2 + floor(584 / 128) = 2 + 4 = 6
```

`memory_explanation` reads `0.6GB available of 2.0GB (cgroup, capped by host free memory), 128MB/worker`.

### Example 6: all three scopes, and a pod-level limit

A Kubernetes pod with a 4096 MB pod-level limit and no limit on the container, on a 65536 MB node
that uses 30000 MB. The container's cgroup reports a working set of 2500 MB.

Without `memory_limit_mb`, only the `host` scope is measured (the container has no limit of its
own), and it is the primary scope:

```text
scopeHeadroomMb     = 65536 * 0.85 - 30000 = 25705.6
additional workers  = floor(25705.6 / 128)  = 200      -- the pod is OOM-killed long before this
```

With `QUEUE_AUTOSCALE_MEMORY_LIMIT_MB=4096`, the `configured` scope is primary and uses the cgroup's
working set:

```text
scopeHeadroomMb     = 4096 * 0.85 - 2500 = 981.6
host free 35536, not lower
additional workers  = floor(981.6 / 128) = 7
```

If the container also had its own 3072 MB limit, all three scopes would be measured. `configured`
stays primary, and the `cgroup` scope's free memory, `3072 - 2500 = 572`, is lower than 981.6, so:

```text
scopeHeadroomMb     = 572                   (memory_scope: configured, memory_capped_by: cgroup)
additional workers  = floor(572 / 128) = 4
```

### Example 7: booting workers

Continuing Example 5 (headroom 640.8 MB, estimate 128 MB). The previous cycle spawned three workers
less than 30 seconds ago. When usage was measured their `VmRSS` was 40 MB and 60 MB, and the third
had been spawned after the reading:

```text
settlingChargeMb    = (128 - 40) + (128 - 60) + 128 = 88 + 68 + 128 = 284
headroomMb          = max(640.8 - 284, 0) = 356.8
additional workers  = floor(356.8 / 128) = 2           (settling_workers: 3)
```

Without the charge this cycle would add five more workers on top of three that have not reached
their footprint yet.

### Example 8: the peak-memory floor

The queue's per-job memory measures 96 MB. Its four live workers peaked at 150, 152, 155 and
410 MB (one ran an unusually large import):

```text
sorted              = [150, 152, 155, 410]
n = 4 (< 10)        -> lower median, index intdiv(4 - 1, 2) = 1 -> 152
estimate            = max(96, 152) = 152 MB            (source: measured, 4 samples)
```

The 410 MB outlier does not set the cost. With twelve live workers peaking at 150, 151, … 160 and
one at 410:

```text
n = 12 (>= 10)      -> nearest-rank p90, index ceil(0.9 * 12) - 1 = 10 -> 160
```

With a single live worker the floor is that worker's peak; with two it is the lower of the two.

### Example 9: an OOM hold

A host runs 14 workers. Between two metrics refreshes the cgroup's OOM kill counter rises from 3 to
4, so a hold starts for 300 seconds and the ceiling is fixed at 14 on the next calculation.

```text
CPU and memory now allow 20     -> finalMaxWorkers = 14, maxWorkersByMemory = 14
                                   limitingFactor: memory, oom_backoff_ceiling: 14
CPU and memory allow only 10    -> finalMaxWorkers = 10 (the hold never raises a ceiling)
```

Five minutes later the hold expires, `oom_backoff_ceiling` and `oom_backoff_until` return to null,
and the next calculation may allow 20 again.

## Interaction with policies

`NoScaleDownPolicy` blocks scale-down decisions **except** when
`currentWorkers > capacity->finalMaxWorkers` — resource-forced reductions are always allowed
through, because the alternative is running workers the host cannot support. It takes a
`CapacityCalculator` by constructor injection.

## Properties

- **O(1)** arithmetic per queue.
- One system-metrics read per 4-second cache window, shared across every queue in the tick. CPU is
  diffed against the previous tick's counters, so nothing blocks.
- One `/proc/<pid>/status` read per live worker per cycle for the peak-memory floor, and one per
  settling worker per metrics refresh.
- No persistent storage; measured estimates live in the resolver for the manager's lifetime.

## See also

- [Architecture](architecture.md) — where capacity sits in the decision pipeline
- [Backlog Drain](backlog-drain.md) — what produces the target being capped
- [Performance Tuning](../basic-usage/performance.md) — choosing limits for your host
