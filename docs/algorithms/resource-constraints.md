---
title: "Resource Constraints"
description: "How CPU and memory capacity, per-worker resource estimates and config bounds cap the worker target"
weight: 54
---

# Resource Constraints

The strategy answers "how many workers does this queue need?". Resource constraints answer "how
many can this host actually run?". `ScalingEngine` applies the second to the first.

Three source files do all of the work:

- `src/Scaling/Calculators/CapacityCalculator.php` — CPU and memory capacity from live system metrics
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

On Linux, memory is then floored at what the workload's live workers have actually peaked at, from
each worker's `VmHWM` (peak resident memory since it started, from `/proc/<pid>/status`), read at the
start of every cycle. With ten or more workers the floor is their 90th percentile; with fewer it is
the lower median, because a 90th percentile of a handful of samples is just the highest one, and a
single worker that once ran an unusually heavy job would otherwise set the cost of every worker on
the queue. The per-job measurement is sampled while a
job runs, so it misses a worker's boot peak (loading the framework, filling OPcache), which is when
a host packed to its limit gets OOM-killed. The floor only ever raises an estimate. The per-host
capacity a cluster manager reports uses the global default floored the same way across all of the
host's workers. Where `/proc` is not available, nothing changes.

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

## Capacity calculation

`CapacityCalculator::calculateMaxWorkers(int $currentWorkers, ResourceEstimate $estimate)` returns a
`CapacityCalculationResult`. `$currentWorkers` is the **total workers already running on this host
across all queues**, and it appears in both formulas:

```text
availableCpuPercent    = max(limits.max_cpu_percent - currentCpuPercent, 0)
usableCores            = max(totalCores - limits.reserve_cpu_cores, 0)
availableCoreEquiv     = usableCores * (availableCpuPercent / 100)
maxWorkersByCpu        = currentWorkers + floor(availableCoreEquiv / max(cpuCoresPerWorker, 0.01))

headroomMb             = min(
                             limitMb * (limits.max_memory_percent / 100) - usedMb,
                             free memory of every other scope that applies
                         ) - settlingWorkers * memoryMbPerWorker
maxWorkersByMemory     = currentWorkers + floor(max(headroomMb, 0) / max(memoryMbPerWorker, 1.0))

finalMaxWorkers        = max(min(maxWorkersByCpu, maxWorkersByMemory), 0)
```

The `currentWorkers +` term is not optional. The percentages measured are *current* usage, which
already includes the running workers — so the division yields how many workers can be **added**, and
the running ones must be added back to get a total ceiling. Dropping the term would make the
autoscaler terminate workers it had just decided were affordable.

`limitingFactor` is `'cpu'` when `maxWorkersByCpu < maxWorkersByMemory`, `'memory'` in the reverse
case, and `'balanced'` when they are equal.

### Which memory limit applies

Memory is read in up to three scopes, each pairing a limit with usage measured against that same limit:

| Scope | Limit | Used | Applies when |
|---|---|---|---|
| `host` | `MemTotal` | `MemTotal - MemAvailable` | always (Linux `/proc/meminfo`, or the platform equivalent) |
| `cgroup` | the container's `memory.max` / `memory.limit_in_bytes` | the cgroup's usage | the container has its own memory limit |
| `configured` | `limits.memory_limit_mb` | the cgroup's usage, else the host's | it is set |

`max_memory_percent` applies to the most specific scope (`configured`, else `cgroup`, else `host`). Every other scope caps the headroom at what it has free, so a container on a crowded node cannot claim memory the node does not have. `MemAvailable` already excludes reclaimable page cache.

A process cannot see a memory limit set only on a parent cgroup, such as a Kubernetes pod-level limit or an Amazon ECS task-level limit with no container limit. There the host scope applies, and in a VM-backed container it can describe far more memory than the container may use. Give the container its own limit, or set `limits.memory_limit_mb` (`QUEUE_AUTOSCALE_MEMORY_LIMIT_MB`).

### Workers that are still booting

A worker grows to its footprint while it boots (loading the framework, filling OPcache), so its memory does not show as used straight away. Workers spawned within `limits.worker_settle_seconds` (default 30) are charged their full `memoryMbPerWorker` on top of current usage. Without this, a batch spawned in one cycle reads as free memory in the next, and the next batch overshoots.

### System metrics and caching

Live values come from `SystemMetrics`: `limits()` for total cores, `cpuUsage(1.0)`
for current CPU, `memory()` and `container()` for memory (see above). The CPU sample **blocks for one second**, so results
are cached for `CACHE_TTL_SECONDS = 4.0` — one measurement per evaluation tick, not one per queue.
`invalidateCache()` forces a fresh read.

Degradation is layered:

- `SystemMetrics::limits()` fails — the whole calculation is abandoned and a fixed fallback is
  returned: `maxWorkersByCpu: 5`, `maxWorkersByMemory: 5`, `maxWorkersByConfig: PHP_INT_MAX`,
  `finalMaxWorkers: 5`, `limitingFactor: 'system_metrics_unavailable'`.
- Only the CPU read fails — current CPU is assumed to be `50.0%`.
- No memory scope can be read — current memory is assumed to be `50.0%` of the total `limits()` reports.

### Configuration

```php
// config/queue-autoscale.php
'limits' => [
    'max_cpu_percent' => 85,            // host CPU headroom ceiling
    'max_memory_percent' => 85,         // host memory headroom ceiling
    'worker_memory_mb_estimate' => 128, // default MB per worker
    'worker_cpu_core_estimate' => 0.2,  // default cores per worker
    'reserve_cpu_cores' => 0.2,         // cores held back for the OS and the manager
    'memory_limit_mb' => null,          // explicit memory limit, for one this process cannot see
    'worker_settle_seconds' => 30,      // how long a new worker is charged its full memory estimate
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

### After OOM kills

When the kernel OOM-kills processes on the host, the memory they held is freed, so the next capacity reading looks like room for more workers and the manager would spawn straight back into the limit that killed them. The calculator watches the cgroup's OOM kill counter (`SystemMetrics::container()->oomKillCount`). When it rises between readings, `finalMaxWorkers` is held at the worker count at that moment for `limits.oom_backoff_seconds` (default 300, env `QUEUE_AUTOSCALE_OOM_BACKOFF_SECONDS`, `0` disables), the limiting factor reads `memory`, and a warning is logged. `memory_details` carries `oom_backoff_ceiling` and `oom_backoff_until`.

Only the counter triggers this. A worker dying from SIGKILL is not enough on its own, because `queue:work` also SIGKILLs itself when a job exceeds `--timeout`. Where the counter is not available (outside Linux cgroups) nothing changes.

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
| `limitingFactor` | `'cpu'`, `'memory'`, `'balanced'`, `'system_metrics_unavailable'`, or after the engine `'config'`, `'strategy'`, `'fuse'` |
| `details` | `cpu_explanation`, `memory_explanation`, and nested `cpu_details` / `memory_details` including the estimate sources. Prefer the typed `cpuBreakdown()` / `memoryBreakdown()` accessors over indexing into it |

Helpers: `isCpuLimited()`, `isMemoryLimited()`, `isConfigLimited()`, `getSummary()`,
`getFormattedDetails()`. The `-vvv` output of `queue:autoscale` prints `getFormattedDetails()`
verbatim.

The engine's `determineFinalLimitingFactor()` resolves the post-clamp label: `'config'` when
`workers.max` capped the target, `'strategy'` when `workers.min` raised it or nothing constrained
it, and the capacity result's own factor when system capacity cut the strategy recommendation.
A tripped fuse overrides all of them with `'fuse'`.

## Worked examples

### Example 1: CPU is the limiting factor

| Input | Value |
|---|---|
| Total cores | 8 |
| Current CPU | 60% |
| Total memory | 16384 MB |
| Current memory | 50% |
| Workers on host | 6 |
| Estimate | 0.2 cores, 128 MB per worker |

```text
availableCpuPercent = max(85 - 60, 0) = 25
usableCores         = max(8 - 0.2, 0) = 7.8
availableCoreEquiv  = 7.8 * 0.25 = 1.95
maxWorkersByCpu     = 6 + floor(1.95 / 0.2) = 6 + 9 = 15

availableMemPercent = max(85 - 50, 0) = 35
maxWorkersByMemory  = 6 + floor(16384 * 0.35 / 128) = 6 + 44 = 50

finalMaxWorkers     = min(15, 50) = 15        (limitingFactor: cpu)
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
maxWorkersByCpu    = 6 + floor(1.95 / 0.5)          = 6 + 3  = 9
maxWorkersByMemory = 6 + floor(16384 * 0.35 / 2048) = 6 + 2  = 8

finalMaxWorkers    = min(9, 8) = 8                  (limitingFactor: memory)
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

## Interaction with policies

`NoScaleDownPolicy` blocks scale-down decisions **except** when
`currentWorkers > capacity->finalMaxWorkers` — resource-forced reductions are always allowed
through, because the alternative is running workers the host cannot support. It takes a
`CapacityCalculator` by constructor injection.

## Properties

- **O(1)** arithmetic per queue.
- One blocking CPU sample per 4-second cache window, shared across every queue in the tick.
- No persistent storage; measured estimates live in the resolver for the manager's lifetime.

## See also

- [Architecture](architecture.md) — where capacity sits in the decision pipeline
- [Backlog Drain](backlog-drain.md) — what produces the target being capped
- [Performance Tuning](../basic-usage/performance.md) — choosing limits for your host
