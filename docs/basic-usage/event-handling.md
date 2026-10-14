---
title: "Event Handling"
description: "Complete guide to using Laravel events with Queue Autoscale for notifications and integrations"
weight: 15
---

# Event Handling

Complete guide to using Laravel events with Queue Autoscale.

## Table of Contents
- [Overview](#overview)
- [Available Events](#available-events)
- [Listening to Events](#listening-to-events)
- [Event Payloads](#event-payloads)
- [Common Use Cases](#common-use-cases)
- [Best Practices](#best-practices)

## Overview

Queue Autoscale for Laravel dispatches Laravel events at key points during the autoscaling lifecycle. You can listen to these events to:
- Send custom notifications
- Collect metrics
- Trigger external workflows
- Audit scaling decisions
- Integrate with other systems

### Events vs Policies

**Events** are Laravel's native event system — decoupled, broadcast to all listeners, and unable to change anything.
**Policies** run in order as part of the scaling pipeline, on the `ScalingDecision` the engine already produced, and can replace it.

Policies never run *before* the strategy. The order is: metrics → strategy → host capacity → config bounds → failure fuse → `ScalingDecision` → `PolicyExecutor::beforeScaling()` → spawn/terminate → `PolicyExecutor::afterScaling()` → events.

Use **Events** when:
- Multiple systems need to react independently
- You want loose coupling
- You're using Laravel's existing event infrastructure

Use **Policies** when:
- You need guaranteed execution order
- You want to modify scaling behavior
- You need to enforce constraints

## Available Events

Queue Autoscale emits workload events, cluster events, and manager lifecycle events. This page focuses on the most commonly consumed workload events; for the complete integration surface see [Integrations & Developer Hooks](../advanced-usage/integrations.md).

### `ScalingDecisionMade`

Fired every evaluation cycle after the scaling engine computes a decision — even when the decision is HOLD.

```php
namespace Cbox\LaravelQueueAutoscale\Events;

class ScalingDecisionMade
{
    public function __construct(
        public readonly ScalingDecision $decision,
    ) {}
}
```

`$decision` carries `connection`, `queue`, `currentWorkers`, `targetWorkers`, `reason`, `predictedPickupTime`, `slaTarget`, and a `capacity` DTO. See `ScalingDecision` source for the full shape.

**Use for:** logging every decision, analytics, dashboards showing scaler activity.

### `SlaBreachPredicted`

Fired every cycle where the predicted pickup time exceeds the SLA target — i.e. the forecaster expects a breach before we can scale up enough.

```php
class SlaBreachPredicted
{
    public function __construct(
        public readonly ScalingDecision $decision,
    ) {}
}
```

**Use for:** early warnings, pre-breach notifications. **Fires per cycle** during sustained risk — rate-limit your listener (see [AlertRateLimiter](../cookbook/_index.md)).

### `SlaBreached`

Fired **once** when the oldest pending job crosses the SLA target — a state transition, not per cycle.

```php
class SlaBreached
{
    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly int $oldestJobAge,
        public readonly int $slaTarget,
        public readonly int $pending,
        public readonly int $activeWorkers,
    ) {}

    public function breachSeconds(): int;      // how far over SLA
    public function breachPercentage(): float; // same, as %
}
```

**Use for:** paging, alert creation, incident tracking.

### `SlaRecovered`

Fired **once** when the queue drops back under its SLA target — the counterpart to `SlaBreached`.

```php
class SlaRecovered
{
    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly int $currentJobAge,
        public readonly int $slaTarget,
        public readonly int $pending,
        public readonly int $activeWorkers,
    ) {}

    public function marginSeconds(): int;       // buffer below SLA
    public function marginPercentage(): float;
}
```

**Use for:** closing alerts, MTTR tracking.

### `WorkersScaled`

Fired whenever workers actually spawn or terminate. Also fired by the exclusive-queue supervisor when respawning a pinned worker.

```php
class WorkersScaled
{
    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly int $from,
        public readonly int $to,
        public readonly string $action,     // 'up' | 'down'
        public readonly string $reason,
    ) {}
}
```

For group workers, `$queue` holds the group name. For supervisor respawns on exclusive queues, `$reason` is `'supervisor:respawn'` or `'supervisor:trim'`.

**Use for:** cost accounting, scaling audit logs.

### `FuseTripped` / `FuseProbing` / `FuseRecovered`

Fired when the [failure fuse](failure-fuse.md) changes state — on transitions only, never per cycle.

```php
class FuseTripped
{
    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly float $failureRate,
        public readonly int $samples,
        public readonly int $failures,
        public readonly float $thresholdPercent,
        public readonly int $heldAtWorkers,
    ) {}
}
```

`FuseProbing` carries `$probeWorkers` and `$cooldownSeconds`; `FuseRecovered` carries `$failureRate` and `$samples`.

`FuseTripped` is a stronger signal than an SLA breach: the queue's work is *failing*, not merely late. Because these fire once per transition, they do not need rate-limiting.

**Use for:** incident alerting, incident duration metrics. See [Alert on a Fuse Trip](../cookbook/alert-on-fuse-trip.md).

### `WorkerExited`

Fired once for every worker process the manager finds has exited, whether it crashed, was killed, or stopped itself — including while the manager is shutting down. A worker that exits is removed from the pool and replaced on a later cycle if the queue still needs it.

```php
namespace Cbox\LaravelQueueAutoscale\Events;

class WorkerExited
{
    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly ?string $group,
        public readonly ?int $pid,
        public readonly ?int $exitCode,
        public readonly ?int $termSignal,
        public readonly bool $terminationRequested,
        public readonly int $uptimeSeconds,
        public readonly bool $graceful,
    ) {}
}
```

| Property | Meaning |
|---|---|
| `$connection` | The queue connection the worker ran against |
| `$queue` | The queue it polled. For a group worker, the comma-separated queue list it polled |
| `$group` | The group name for a group worker, otherwise `null` |
| `$pid` | The worker's process ID, or `null` if it never started |
| `$exitCode` | The process exit code. A worker killed by a signal reports `128 + signal`, so SIGKILL reads `137` |
| `$termSignal` | The signal that ended the process (`9` for SIGKILL, `15` for SIGTERM), or `null` when it exited on its own |
| `$terminationRequested` | `true` when the manager had asked the worker to stop: a scale-down, a restart or shutdown |
| `$uptimeSeconds` | Seconds from spawn until the manager noticed the exit |
| `$graceful` | `true` when `queue:work` stopped itself cleanly: no terminating signal, and exit code `0` or `12` |

`queue:work` exits `0` when it stops on request, at `--max-time`, on a restart signal or when its connection is lost, and `12` when it stops at its `--memory` limit. The autoscaler never passes `--memory`, so `queue:work`'s default of 128 MB applies: a worker whose PHP memory usage passes it exits with `12` after its current job, and is replaced if the queue still needs it. Any other exit is not graceful.

How common exits read:

| What happened | `$exitCode` | `$termSignal` | `$terminationRequested` | `$graceful` |
|---|---|---|---|---|
| Scale-down: the manager sent SIGTERM and the worker finished its job | `0` | `null` | `true` | `true` |
| The worker reached `workers.max_time_seconds` (`--max-time`) | `0` | `null` | `false` | `true` |
| The worker passed `queue:work`'s `--memory` limit (128 MB by default) after a job | `12` | `null` | `false` | `true` |
| A job ran past `workers.timeout_seconds`: `queue:work` SIGKILLs itself | `137` | `9` | `false` | `false` |
| The kernel OOM-killed the worker | `137` | `9` | `false` | `false` |
| The worker ignored SIGTERM past its shutdown timeout and the manager SIGKILLed it | `137` | `9` | `true` | `false` |
| The worker process exited on the manager's SIGTERM instead of handling it | `143` | `15` | `true` | `false` |
| A fatal error or uncaught exception outside a job | `255` (or `1`) | `null` | `false` | `false` |

A SIGKILL with `$terminationRequested` false is either a job timeout or something outside the manager, most often the OOM killer. The two are told apart elsewhere: a timed-out job is marked failed with a `TimeoutExceededException` once its attempts run out, while the kernel logs an OOM kill (`dmesg`, `OOMKilled` in Kubernetes) and, in a container, the manager logs its OOM backoff. See [Troubleshooting](troubleshooting.md#workers-spawn-but-die-within-seconds).

The same details are logged as `Removed dead worker`, at `info` for a graceful exit and `warning` otherwise, with the context keys `pid`, `connection`, `queue`, `group`, `exit_code`, `term_signal`, `termination_requested` and `uptime_seconds`. With `-v`, the console line `Removed dead worker: PID …` is styled to match.

During shutdown, an exception thrown by a `WorkerExited` listener is logged and ignored, so it cannot stop the manager from force-killing workers that ignore SIGTERM. Outside shutdown, a throwing listener is handled like any other error in an evaluation cycle: it is logged as `Autoscale evaluation failed`, the rest of that cycle is skipped, and the next cycle carries on. Keep listeners cheap and defensive, or queue the work they do.

```php
use Cbox\LaravelQueueAutoscale\Events\WorkerExited;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

Event::listen(function (WorkerExited $event): void {
    if ($event->graceful || $event->terminationRequested) {
        return;
    }

    Log::warning('Queue worker died unexpectedly', [
        'queue' => $event->queue,
        'exit_code' => $event->exitCode,
        'signal' => $event->termSignal,
        'uptime_seconds' => $event->uptimeSeconds,
        'possible_oom_or_timeout' => $event->termSignal === SIGKILL,
    ]);
});
```

**Use for:** crash alerting, spotting OOM kills, worker lifetime metrics.

## Listening to Events

### Method 1: Event Listeners

Create a dedicated listener class:

```php
<?php

namespace App\Listeners;

use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;

class LogScalingDecision
{
    public function handle(ScalingDecisionMade $event): void
    {
        $decision = $event->decision;

        logger()->info('Scaling decision made', [
            'connection' => $decision->connection,
            'queue' => $decision->queue,
            'current_workers' => $decision->currentWorkers,
            'target_workers' => $decision->targetWorkers,
            'action' => $decision->action(),
            'reason' => $decision->reason,
            'predicted_pickup_time' => $decision->predictedPickupTime,
            'limiting_factor' => $decision->capacity?->limitingFactor,
        ]);
    }
}
```

Register in `EventServiceProvider`:

```php
protected $listen = [
    \Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade::class => [
        \App\Listeners\LogScalingDecision::class,
    ],
    \Cbox\LaravelQueueAutoscale\Events\WorkersScaled::class => [
        \App\Listeners\RecordWorkerMetrics::class,
    ],
    \Cbox\LaravelQueueAutoscale\Events\SlaBreached::class => [
        \App\Listeners\AlertOnSlaBreach::class,
    ],
    \Cbox\LaravelQueueAutoscale\Events\SlaRecovered::class => [
        \App\Listeners\CloseSlaIncident::class,
    ],
];
```

### Method 2: Closure Listeners

For simple cases, use closures in a service provider:

```php
use Illuminate\Support\Facades\Event;
use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;

public function boot(): void
{
    Event::listen(function (ScalingDecisionMade $event) {
        logger()->info('Scaling decision', [
            'queue' => $event->decision->queue,
            'target' => $event->decision->targetWorkers,
        ]);
    });
}
```

### Method 3: Queued Listeners

For heavy processing, queue the listener:

```php
<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;

class RecordWorkerMetrics implements ShouldQueue
{
    public function handle(WorkersScaled $event): void
    {
        // Heavy processing - runs on queue
        app(MetricsService::class)->recordScalingEvent([
            'queue' => $event->queue,
            'previous' => $event->from,
            'new' => $event->to,
            'direction' => $event->action,
        ]);
    }
}
```

## Event Payloads

### `ScalingDecisionMade` / `SlaBreachPredicted` Payload

Both events carry a single `$decision` property of type `ScalingDecision`:

```php
$event->decision->connection            // 'redis'
$event->decision->queue                 // 'default'
$event->decision->currentWorkers        // 5
$event->decision->targetWorkers         // 10
$event->decision->reason                // 'Little\'s Law + backlog drain'
$event->decision->predictedPickupTime   // float|null — null when p95 unavailable
$event->decision->slaTarget             // 30
$event->decision->capacity              // CapacityCalculationResult|null
$event->decision->spawnCompensation     // SpawnCompensationConfiguration|null
```

`$event->decision->capacity` (when present) exposes `maxWorkersByCpu`, `maxWorkersByMemory`, `maxWorkersByConfig`, `finalMaxWorkers`, `limitingFactor` (a `LimitingFactor` enum: `cpu`, `memory`, `balanced`, `config`, `strategy`, `fuse` or `system_metrics_unavailable`) and the `details` array, with the typed `cpuBreakdown()` and `memoryBreakdown()` accessors. See [Scaling Decision](../api-reference/scaling-decision.md) for every field.

### `WorkersScaled` Payload

```php
$event->connection       // 'redis'
$event->queue            // 'default' (or group name for group workers)
$event->from             // 5
$event->to               // 10
$event->action           // 'up' | 'down'
$event->reason           // 'Little\'s Law + backlog drain' | 'supervisor:respawn' | ...
```

Calculate change:

```php
$workerChange = $event->to - $event->from;
$scalingUp = $event->action === 'up';
$scalingDown = $event->action === 'down';
```

### `SlaBreached` / `SlaRecovered` Payload

```php
$event->connection      // 'redis'
$event->queue           // 'default'
$event->oldestJobAge    // int seconds (SlaBreached only — SlaRecovered uses ->currentJobAge)
$event->slaTarget       // int seconds
$event->pending         // int — pending jobs at the moment the event fired
$event->activeWorkers   // int

// Convenience methods:
$event->breachSeconds()        // SlaBreached
$event->breachPercentage()     // SlaBreached
$event->marginSeconds()        // SlaRecovered
$event->marginPercentage()     // SlaRecovered
```

## Common Use Cases

> **Note:** the events carry only what is listed above. Queue depth, throughput and job durations are **not** on them — fetch those from the `QueueMetrics` facade provided by `cboxdk/laravel-queue-metrics`. Any `DB::table('autoscale_*')` reference below is your own table; this package ships no such migrations.
>
> For production-ready alerting with no external dependencies, use the recipes in the [Cookbook](../cookbook/_index.md).

### Use Case 1: Slack Notifications

Send rich Slack messages on scaling events:

```php
<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Http;
use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;

class SendSlackNotification
{
    public function handle(ScalingDecisionMade $event): void
    {
        $decision = $event->decision;
        $workerChange = $decision->targetWorkers - $decision->currentWorkers;

        if (abs($workerChange) < 5) {
            return; // Only notify for significant changes
        }

        $color = $workerChange > 0 ? '#36a64f' : '#ff9900';
        $direction = $workerChange > 0 ? '⬆️ Scaling UP' : '⬇️ Scaling DOWN';

        Http::post(config('services.slack.webhook'), [
            'attachments' => [
                [
                    'color' => $color,
                    'title' => "{$direction}: {$decision->queue}",
                    'fields' => [
                        [
                            'title' => 'Worker Change',
                            'value' => "{$decision->currentWorkers} → {$decision->targetWorkers}",
                            'short' => true,
                        ],
                        [
                            'title' => 'Limiting Factor',
                            'value' => $decision->capacity?->limitingFactor ?? 'n/a',
                            'short' => true,
                        ],
                        [
                            'title' => 'Reason',
                            'value' => $decision->reason,
                            'short' => false,
                        ],
                    ],
                    'footer' => 'Queue Autoscale',
                    'ts' => time(),
                ],
            ],
        ]);
    }
}
```

`ScalingDecisionMade` fires on **every** evaluation cycle, so a webhook listener like this should also be gated through `AlertRateLimiter` in production.

### Use Case 2: Metrics Collection

Send metrics to Datadog, CloudWatch, etc:

```php
<?php

namespace App\Listeners;

use App\Services\DatadogClient;
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;

class RecordWorkerMetrics
{
    public function __construct(
        private readonly DatadogClient $datadog
    ) {}

    public function handle(WorkersScaled $event): void
    {
        $tags = [
            "queue:{$event->queue}",
            "connection:{$event->connection}",
            "direction:{$event->action}",
        ];

        // Record worker count
        $this->datadog->gauge('queue.autoscale.workers', $event->to, $tags);

        // Record worker change
        $change = $event->to - $event->from;
        $this->datadog->gauge('queue.autoscale.worker_change', abs($change), $tags);

        // Increment scaling events
        $this->datadog->increment('queue.autoscale.events', 1, $tags);
    }
}
```

### Use Case 3: Worker-Hour Accounting

Record every scaling action to your own table so you can attribute worker-hours later:

```php
<?php

namespace App\Listeners;

use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;
use Illuminate\Support\Facades\DB;

class RecordScalingActions
{
    public function handle(WorkersScaled $event): void
    {
        DB::table('autoscale_scaling_log')->insert([
            'connection' => $event->connection,
            'queue' => $event->queue,
            'previous_workers' => $event->from,
            'new_workers' => $event->to,
            'worker_change' => $event->to - $event->from,
            'direction' => $event->action,   // 'up' | 'down'
            'reason' => $event->reason,
            'recorded_at' => now(),
        ]);
    }
}
```

`autoscale_scaling_log` is your own migration — this package ships none.

### Use Case 4: PagerDuty Alerts on SLA breach

Alert on-call when an SLA breach actually starts (note: `SlaBreached` fires once per state transition, so no rate-limiting is strictly required — but use `AlertRateLimiter` if you want to dedup across rapid flapping):

```php
<?php

namespace App\Listeners;

use App\Services\PagerDutyClient;
use Cbox\LaravelQueueAutoscale\Alerting\AlertRateLimiter;
use Cbox\LaravelQueueAutoscale\Events\SlaBreached;

class AlertOnSlaBreach
{
    public function __construct(
        private readonly PagerDutyClient $pagerDuty,
        private readonly AlertRateLimiter $limiter,
    ) {}

    public function handle(SlaBreached $event): void
    {
        if (! $this->limiter->allow("pagerduty:breach:{$event->connection}:{$event->queue}")) {
            return;
        }

        $this->pagerDuty->trigger([
            'summary' => "Queue SLA breach: {$event->connection}:{$event->queue}",
            'severity' => 'error',
            'source' => 'laravel-queue-autoscale',
            'custom_details' => [
                'connection' => $event->connection,
                'queue' => $event->queue,
                'oldest_job_age_seconds' => $event->oldestJobAge,
                'sla_target_seconds' => $event->slaTarget,
                'breach_seconds' => $event->breachSeconds(),
                'pending' => $event->pending,
                'active_workers' => $event->activeWorkers,
            ],
        ]);
    }
}
```

### Use Case 5: Audit Logging

Maintain detailed audit trail:

```php
<?php

namespace App\Listeners;

use Illuminate\Support\Facades\DB;
use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;

class AuditScalingDecisions
{
    public function handle(ScalingDecisionMade $event): void
    {
        $decision = $event->decision;
        $metrics = QueueMetrics::getQueueMetrics($decision->connection, $decision->queue);

        DB::table('scaling_audit_log')->insert([
            'connection' => $decision->connection,
            'queue' => $decision->queue,
            'current_workers' => $decision->currentWorkers,
            'target_workers' => $decision->targetWorkers,
            'worker_change' => $decision->targetWorkers - $decision->currentWorkers,
            'reason' => $decision->reason,
            'predicted_pickup_time' => $decision->predictedPickupTime,
            'sla_target' => $decision->slaTarget,
            'limiting_factor' => $decision->capacity?->limitingFactor,

            // Queue state comes from the metrics package, not from the event.
            'pending_jobs' => $metrics->pending,
            'oldest_job_age' => $metrics->oldestJobAge,
            'throughput_per_minute' => $metrics->throughputPerMinute,

            'created_at' => now(),
        ]);
    }
}
```

Add the metrics facade import alongside the event import:

```php
use Cbox\LaravelQueueMetrics\Facades\QueueMetrics;
```

Note that this runs inside the manager daemon on every cycle — a synchronous insert per queue per cycle is a real cost. Batch it, or queue the listener, if you have many queues.

### Use Case 6: External Workflow Integration

Trigger external systems:

```php
<?php

namespace App\Listeners;

use App\Services\JenkinsClient;
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;

class TriggerLoadTestOnScaling
{
    public function __construct(
        private readonly JenkinsClient $jenkins
    ) {}

    public function handle(WorkersScaled $event): void
    {
        // Only for production queue
        if ($event->queue !== 'production') {
            return;
        }

        // Only when scaling up significantly
        if ($event->action !== 'up' || $event->to < 20) {
            return;
        }

        // Trigger load test to verify capacity
        $this->jenkins->triggerBuild('queue-load-test', [
            'queue' => $event->queue,
            'worker_count' => $event->to,
            'trigger' => 'autoscale_event',
        ]);
    }
}
```

## Best Practices

### 1. Keep Listeners Fast

Listeners execute synchronously unless queued. Keep them fast:

```php
// ✅ Good: Fast operation
public function handle(ScalingDecisionMade $event): void
{
    logger()->info('Scaling decision', ['queue' => $event->decision->queue]);
}

// ❌ Bad: Slow operation
public function handle(ScalingDecisionMade $event): void
{
    sleep(5);  // Don't block the autoscaling process!
}

// ✅ Good: Queue heavy work
class HeavyMetricsProcessor implements ShouldQueue
{
    public function handle(ScalingDecisionMade $event): void
    {
        // Heavy processing runs async
    }
}
```

### 2. Handle Failures Gracefully

Don't let listener exceptions break autoscaling:

```php
public function handle(ScalingDecisionMade $event): void
{
    try {
        $this->sendNotification($event);
    } catch (\Exception $e) {
        logger()->error('Notification failed', [
            'error' => $e->getMessage(),
            'queue' => $event->decision->queue,
        ]);
        // Don't throw - allow autoscaling to continue
    }
}
```

### 3. Filter Events Appropriately

Don't process every event if you only care about some:

```php
public function handle(ScalingDecisionMade $event): void
{
    // Only care about one queue
    if ($event->decision->queue !== 'production') {
        return;
    }

    // Only care about significant changes
    $change = abs($event->decision->targetWorkers - $event->decision->currentWorkers);
    if ($change < 5) {
        return;
    }

    // Now process...
}
```

### 4. Use Type Hints

Laravel's event discovery works best with type hints:

```php
// ✅ Good: Type-hinted parameter
public function handle(ScalingDecisionMade $event): void
{
    // Laravel auto-discovers this
}

// ❌ Bad: No type hint
public function handle($event): void
{
    // Requires manual registration
}
```

### 5. Consider Event Order

If order matters, use policies instead:

```php
// Events: All listeners execute (order not guaranteed)
Event::listen(ScalingDecisionMade::class, Listener1::class);
Event::listen(ScalingDecisionMade::class, Listener2::class);

// Policies: Execute in defined order
'policies' => [
    Policy1::class,  // Always executes first
    Policy2::class,  // Always executes second
]
```

`policies` entries must be **class strings**. `PolicyExecutor` filters the config to `is_string($policy) && class_exists($policy)`, so a policy instance or a closure placed in that array is silently dropped. The classes are resolved through `app()`, so constructor injection works.

### 6. Test Event Listeners

Construct the event directly — `ScalingDecisionMade` takes a single `ScalingDecision`, and `ScalingDecision` uses named arguments:

```php
use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;
use Illuminate\Support\Facades\Http;

it('sends a slack notification on a significant scale-up', function () {
    Http::fake();

    $event = new ScalingDecisionMade(
        decision: new ScalingDecision(
            connection: 'redis',
            queue: 'default',
            currentWorkers: 5,
            targetWorkers: 15,
            reason: 'backlog drain',
            predictedPickupTime: 12.5,
            slaTarget: 30,
        ),
    );

    (new SendSlackNotification)->handle($event);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'slack.com'));
});
```

To assert the manager dispatched it, fake the event and drive whatever triggers the cycle in your own test harness:

```php
use Illuminate\Support\Facades\Event;

Event::fake([ScalingDecisionMade::class]);

// ...run your scaling cycle...

Event::assertDispatched(
    ScalingDecisionMade::class,
    fn (ScalingDecisionMade $event) => $event->decision->queue === 'default'
        && $event->decision->targetWorkers > 0,
);
```

## Advanced Patterns

### Pattern: Event Aggregation

Collect multiple events before processing:

```php
class AggregatedMetricsCollector implements ShouldQueue
{
    public function handle(ScalingDecisionMade $event): void
    {
        Cache::remember("scaling_events:{$event->decision->queue}", 300, function () {
            return collect();
        })->push([
            'timestamp' => now(),
            'current_workers' => $event->decision->currentWorkers,
            'target_workers' => $event->decision->targetWorkers,
        ]);

        // Flush every 100 events or 5 minutes
        if ($this->shouldFlush()) {
            $this->flushToDataWarehouse();
        }
    }
}
```

### Pattern: Conditional Queueing

Queue listeners only under certain conditions:

```php
class ConditionallyQueuedListener implements ShouldQueue
{
    public function shouldQueue(ScalingDecisionMade $event): bool
    {
        // Only queue for critical queues
        return in_array($event->decision->queue, ['critical', 'production']);
    }

    public function handle(ScalingDecisionMade $event): void
    {
        // Heavy processing...
    }
}
```

### Pattern: Event Replay

Store events for later replay/analysis:

```php
class EventRecorder
{
    public function handle(ScalingDecisionMade $event): void
    {
        DB::table('event_stream')->insert([
            'event_type' => ScalingDecisionMade::class,
            'event_data' => serialize($event),
            'occurred_at' => now(),
        ]);
    }
}

// Later: Replay events
$events = DB::table('event_stream')
    ->where('occurred_at', '>=', now()->subHours(24))
    ->get();

foreach ($events as $record) {
    $event = unserialize($record->event_data);
    $this->replayEvent($event);
}
```

## See Also

- [Scaling Policies](scaling-policies.md) - Alternative to events for ordered execution
- [Monitoring](monitoring.md) - Monitoring and observability
- [Custom Strategies](../advanced-usage/custom-strategies.md) - Custom scaling strategies
- [API Reference: Events](../api-reference/events.md) - Complete event API documentation
