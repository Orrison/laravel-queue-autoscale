---
title: "Scaling Decision"
description: "The decision object a strategy returns and a policy may adjust, including cluster scope"
weight: 30
---

## Scaling Decision

Returned by `ScalingEngine::evaluate()` and dispatched in `ScalingDecisionMade` / `SlaBreachPredicted` events.

```php
namespace Cbox\LaravelQueueAutoscale\Scaling;

readonly class ScalingDecision
{
    public function __construct(
        public string $connection,
        public string $queue,
        public int $currentWorkers,
        public int $targetWorkers,
        public string $reason,
        public ?float $predictedPickupTime = null,
        public int $slaTarget = 30,
        public ?CapacityCalculationResult $capacity = null,
        public ?SpawnCompensationConfiguration $spawnCompensation = null,
        public ScalingScope $scope = ScalingScope::Host,
        public ?SlaSignal $slaSignal = null,       // since 4.5
    ) {}

    public function withTargetWorkers(int $targetWorkers, ?string $reason = null): self;

    public function shouldScaleUp(): bool;
    public function shouldScaleDown(): bool;
    public function shouldHold(): bool;
    public function workersToAdd(): int;
    public function workersToRemove(): int;
    public function action(): string;           // 'scale_up' | 'scale_down' | 'hold'
    public function isSlaBreachRisk(): bool;    // predictedPickupTime > slaTarget
}
```

There is **no** `confidence` property on `ScalingDecision`, or anywhere else in the package.

`slaSignal` is the SLA signal the engine judged the workload on: what a strategy implementing
`SlaSignalStrategy` reported, else the oldest job's age. It is `null` on a decision built outside
the engine — the cluster leader's per-workload decisions and those a policy constructs with `new`.
`withTargetWorkers()` carries it over, so a policy that adjusts a target that way keeps it. Breach
state is read from the engine's decision, before policies run, so a policy cannot change it. See
[How breach is decided](../basic-usage/monitoring.md#how-breach-is-decided) and the
`SlaSignal` table in [Events](events.md#slabreached--slarecovered-fields).

Do not confuse `ScalingDecision::action()` (`'scale_up' | 'scale_down' | 'hold'`) with `WorkersScaled::$action`, which is always `'up'` or `'down'`.

### `CapacityCalculationResult`

`ScalingDecision::$capacity`, in `Cbox\LaravelQueueAutoscale\Scaling\DTOs`:

```php
readonly class CapacityCalculationResult
{
    public function __construct(
        public int $maxWorkersByCpu,
        public int $maxWorkersByMemory,
        public int $maxWorkersByConfig,
        public int $finalMaxWorkers,
        public LimitingFactor $limitingFactor,
        public array $details = [],
    ) {}

    public function cpuBreakdown(): CpuBreakdown;
    public function memoryBreakdown(): MemoryBreakdown;

    public function isCpuLimited(): bool;
    public function isMemoryLimited(): bool;
    public function isConfigLimited(): bool;
    public function getSummary(): string;
    public function getFormattedDetails(): array;
}
```

`LimitingFactor` is a string-backed enum. The values seen on a decision are `cpu`, `memory`,
`balanced`, `config`, `strategy`, `fuse` and `system_metrics_unavailable`.

### The `details` array

`$details` is part of the public API: its keys are stable within a major version. It has two
strings and two nested arrays.

| Key | Type | Meaning |
|---|---|---|
| `cpu_explanation` | `string` | For example `85% of 8 cores, current usage: 60.0%` |
| `memory_explanation` | `string` | For example `0.6GB available of 2.0GB (cgroup, capped by host free memory), 128MB/worker`: headroom, the primary scope's limit, the scope (and the capping scope, if any) and the per-worker estimate |
| `cpu_details` | `array` | See below |
| `memory_details` | `array` | See below |

`cpu_details`:

| Key | Type | Meaning |
|---|---|---|
| `max_cpu_percent` | `int` | `limits.max_cpu_percent` |
| `current_cpu_percent` | `float` | CPU usage since the previous tick (50.0 on a process's first tick) |
| `available_cpu_percent` | `int\|float` | `max(max_cpu_percent - current_cpu_percent, 0)` |
| `total_cores` | `float` | Cores available to the process, fractional under a cgroup CPU limit |
| `reserve_cores` | `float` | `limits.reserve_cpu_cores` |
| `usable_cores` | `float` | `max(total_cores - reserve_cores, 0)` |
| `worker_cpu_core_estimate` | `float` | Cores one worker is assumed to occupy, at least 0.01 |
| `cpu_estimate_source` | `string` | `measured`, `config` or `default` |

`memory_details`:

| Key | Type | Since | Meaning |
|---|---|---|---|
| `max_memory_percent` | `int` | | `limits.max_memory_percent` |
| `current_memory_percent` | `float` | | `used_memory_mb / total_memory_mb × 100`, for the primary scope |
| `available_memory_percent` | `float` | | `headroom_mb / total_memory_mb × 100` |
| `total_memory_mb` | `float` | | The primary scope's limit: the one `max_memory_percent` applies to. Before 4.4 this was always the host |
| `used_memory_mb` | `float` | 4.4 | Usage measured against that limit. The cgroup working set from 4.4.1 |
| `headroom_mb` | `float` | 4.4 | Memory left for new workers after the `max_memory_percent` ceiling, the cap from every other scope and the settling charge; never negative |
| `memory_scope` | `string` | 4.4 | The primary scope: `configured`, `cgroup`, `host` or `fallback` |
| `memory_capped_by` | `?string` | 4.4 | The other scope whose free memory was lower than the primary's headroom, or `null` |
| `settling_workers` | `int` | 4.4 | Workers spawned within `limits.worker_settle_seconds` and charged the memory they have not grown into yet |
| `worker_memory_mb` | `float` | | The per-worker estimate, at least 1.0 |
| `memory_estimate_source` | `string` | | `measured`, `config` or `default` |
| `oom_backoff_ceiling` | `?int` | 4.4 | The worker ceiling held after an OOM kill, or `null` when no hold is active |
| `oom_backoff_until` | `?string` | 4.4 | When the hold ends, ISO 8601, or `null` |

The fallback result returned when `SystemMetrics::limits()` fails has only `cpu_explanation`,
`memory_explanation` and `error` — no nested arrays. The typed accessors return all-default
objects in that case.

### `CpuBreakdown` and `MemoryBreakdown`

`cpuBreakdown()` and `memoryBreakdown()` return the same numbers as `cpu_details` and
`memory_details`, as typed readonly objects — prefer them over indexing into the array. Every
constructor argument has a default, so `new MemoryBreakdown` is a valid "nothing measured" instance
for a stub.

```php
$cpu = $decision->capacity->cpuBreakdown();

$cpu->maxCpuPercent;          // float
$cpu->currentCpuPercent;      // float
$cpu->availableCpuPercent;    // float
$cpu->totalCores;             // float
$cpu->reserveCores;           // float
$cpu->usableCores;            // float, total minus the reserve
$cpu->workerCpuCoreEstimate;  // float, cores one worker is assumed to occupy
$cpu->estimateSource;         // 'measured', 'config', 'default', or 'unknown' when absent

$memory = $decision->capacity->memoryBreakdown();

$memory->maxMemoryPercent;        // float
$memory->currentMemoryPercent;    // float, of the primary scope
$memory->availableMemoryPercent;  // float, headroom as a percentage of the primary scope's limit
$memory->totalMemoryMb;           // float, the limit max_memory_percent applies to
$memory->usedMemoryMb;            // float, usage measured against that limit
$memory->headroomMb;              // float, room left after the settling charge
$memory->workerMemoryMb;          // float
$memory->estimateSource;          // 'measured', 'config', 'default', or 'unknown' when absent
$memory->scope;                   // ?MemoryScopeKind: Configured, Cgroup, Host or Fallback
$memory->cappedBy;                // ?MemoryScopeKind, the scope whose free memory was lower
$memory->settlingWorkers;         // int, booting workers charged the memory they do not hold yet
$memory->oomBackoffCeiling;       // ?int, worker ceiling held after an OOM kill
$memory->oomBackoffUntil;         // ?string, ISO 8601 end of that hold

$memory->usedMb();                // float, derived from totalMemoryMb and currentMemoryPercent, 1 decimal
$memory->freeMb();                // float, totalMemoryMb - usedMb(), never negative, 1 decimal
```

`MemoryScopeKind` (in the same namespace) is a string-backed enum whose values are the
`memory_scope` strings. `MemoryScopeKind::byPrecedence()` lists them most specific first:
`Configured`, `Cgroup`, `Host`, `Fallback`.

See [Resource Constraints](../algorithms/resource-constraints.md) for how each value is computed,
with worked examples.
