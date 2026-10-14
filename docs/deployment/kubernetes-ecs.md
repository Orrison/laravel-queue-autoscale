---
title: "Kubernetes and Amazon ECS"
description: "Size workers against pod and task memory limits, read the cgroup working set, and hold capacity after OOM kills in containers"
weight: 5
---

# Kubernetes and Amazon ECS

In a container the manager and every worker it spawns share one memory limit, and the kernel
OOM-kills processes that cross it. The manager sizes its workers against that limit — but only if
it can see it. This page covers which limits it can see, what to set when it cannot, and what
happens after an OOM kill. [Docker / Compose](docker.md) covers signals, grace periods and replica
counts.

## Which limit the manager sizes against

Capacity is measured in up to three memory scopes. `limits.max_memory_percent` (default 85) applies
to the most specific one, and the others cap the result at what they have free:

1. **`configured`** — `limits.memory_limit_mb`, when you set it.
2. **`cgroup`** — the container's own cgroup limit, when it has one.
3. **`host`** — the node's total memory.

The full rules and worked examples are in
[Resource Constraints](../algorithms/resource-constraints.md#which-memory-limit-applies). The
question for a container is whether a limit exists on the container's **own** cgroup:

| Platform | Setting | Container cgroup limit? | What to do |
|---|---|---|---|
| Docker / Compose | `mem_limit`, or `deploy.resources.limits.memory` | Yes | Nothing; it is detected |
| Kubernetes | `resources.limits.memory` on the container | Yes | Nothing; it is detected |
| Kubernetes | `resources.requests.memory` only | No | Set a container limit, or `QUEUE_AUTOSCALE_MEMORY_LIMIT_MB` |
| Kubernetes | A pod-level `spec.resources.limits.memory` only | No — it is on the parent cgroup | Set `QUEUE_AUTOSCALE_MEMORY_LIMIT_MB` |
| Amazon ECS | Container `memory` (hard limit) | Yes | Nothing; it is detected |
| Amazon ECS | Container `memoryReservation` only | No | Set `QUEUE_AUTOSCALE_MEMORY_LIMIT_MB` |
| Amazon ECS / Fargate | Task-level `memory` only | No — it is on the parent cgroup | Set `QUEUE_AUTOSCALE_MEMORY_LIMIT_MB` |
| systemd | `MemoryMax=` on a parent slice | No | Set `QUEUE_AUTOSCALE_MEMORY_LIMIT_MB` |

A process cannot see a limit set only on a parent cgroup. Without `memory_limit_mb` the manager
then sizes against the node's memory — on a 64 GB node, a pod limited to 4 GB looks like it has
room for hundreds of workers, and is OOM-killed long before it gets there.

### Setting the limit explicitly

```yaml
# Kubernetes, pod-level limit only
spec:
  resources:
    limits:
      memory: 4Gi
  containers:
    - name: queue-autoscale
      command: ["php", "artisan", "queue:autoscale"]
      env:
        - name: QUEUE_AUTOSCALE_MEMORY_LIMIT_MB
          value: "4096"
```

```json
{
  "family": "queue-autoscale",
  "memory": "4096",
  "containerDefinitions": [
    {
      "name": "queue-autoscale",
      "command": ["php", "artisan", "queue:autoscale"],
      "environment": [
        { "name": "QUEUE_AUTOSCALE_MEMORY_LIMIT_MB", "value": "4096" }
      ]
    }
  ]
}
```

If other containers share that pod- or task-level limit (a log shipper, a sidecar proxy), set
`QUEUE_AUTOSCALE_MEMORY_LIMIT_MB` to what is left for the manager's container, not to the whole
limit. The configured scope measures usage as the container's own working set, so a sidecar's
memory is not counted against it.

When the container also has its own limit, leave `memory_limit_mb` unset unless the parent limit is
the tighter one. If both are set, `memory_limit_mb` is the primary scope and the cgroup's free
memory still caps it, so the result never exceeds either.

## Working set, not raw usage

A container's raw cgroup usage (`memory.current` on cgroup v2, `memory.usage_in_bytes` on v1)
includes page cache: files the container has read or written, such as its own log files. The
kernel reclaims that cache under pressure, so it is not memory the workers are short of.

From 4.4.1 the cgroup scope uses the **working set** instead: usage minus inactive file cache from
`memory.stat` (`inactive_file` on v2, `total_inactive_file` on v1). That is the figure `kubectl top`
reports and the kubelet uses for eviction. Where `memory.stat` cannot be read, raw usage is used.

Before 4.4.1, a container writing logs drifted towards its limit on raw usage alone, its headroom
read as zero, and scale-up stopped while the container was mostly cache.

## After an OOM kill

When the kernel OOM-kills a worker, the memory it held is freed, and the next capacity reading
looks like room for more workers. Spawning straight back into the limit that just killed one would
repeat the kill.

The manager watches the OOM kill counter of its own cgroup: `oom_kill` in `memory.events` on
cgroup v2, and in `memory.oom_control` on cgroup v1 (Linux 4.13 or later, read by
`cboxdk/system-metrics` 3.1 or later — earlier versions read the `under_oom` flag, which never
triggered the hold). When the counter rises between two readings:

- The host's worker capacity is held at its current worker count for
  `limits.oom_backoff_seconds` (default 300, `QUEUE_AUTOSCALE_OOM_BACKOFF_SECONDS`, `0` disables).
- The manager logs `Kernel OOM-killed processes on this host; holding worker capacity at its current
  count` at `warning`, with the number of kills.
- Scaling decisions report `limitingFactor: memory`, and `memory_details` carries
  `oom_backoff_ceiling` and `oom_backoff_until`.
- A further kill during the hold restarts it.

The killed worker appears as a `WorkerExited` event with `$termSignal` 9 and `$exitCode` 137. A job
that ran past `--timeout` looks the same, which is why the hold is triggered by the counter and
never by a SIGKILL alone. See [Event Handling](../basic-usage/event-handling.md#workerexited).

If the OOM killer picks the manager itself, the container exits (Kubernetes reports `OOMKilled`)
and is restarted, and its workers go with it. Outside a container, where workers can outlive a
killed manager, the next manager terminates the workers its predecessor left behind
(`manager.reap_orphans_on_start`, on by default), so a restart does not double the pool.

### Avoiding OOM kills in the first place

- **Measure your workers.** On Linux the per-worker memory estimate is floored at what live workers
  actually peak at, boot included (`VmHWM`), so a low `worker_memory_mb_estimate` corrects itself
  once workers run. Set it close to the real value anyway: it is what the first workers are sized by.
- **Leave headroom.** `max_memory_percent` of 85 leaves 15% for the manager, PHP's own overhead and
  bursts. Lower it for workloads whose jobs vary a lot in size.
- **Keep `worker_settle_seconds` above your boot time.** Workers spawned within it are charged the
  memory they have not grown into yet, so one cycle's batch is not read as free memory in the next.
  The default of 30 seconds suits most applications.
- **Bound PHP.** A `memory_limit` in the CLI `php.ini` turns a runaway job into a PHP fatal error in
  one worker instead of a kernel OOM kill that may land on any process in the container.

## Checking what the manager sees

Run the manager with `-vvv`. Every decision prints a capacity breakdown, including a memory line
built from `memory_explanation`:

```text
Memory Limit: 9 workers (1.0GB available of 4.0GB (configured), 128MB/worker)
```

That reads: two workers running, 981.6 MB of headroom under 85% of the configured 4096 MB, room for
seven more at 128 MB each.

`(configured)`, `(cgroup)` or `(host)` is the scope `max_memory_percent` applied to. If it says
`host` inside a container, the manager cannot see your limit — set `QUEUE_AUTOSCALE_MEMORY_LIMIT_MB`.
`capped by … free memory` names a scope whose free memory was lower, typically a crowded node.

In cluster mode, each manager's heartbeat reports `memory_total_mb` for its primary scope, so
`php artisan queue:autoscale:cluster --json` shows at a glance which managers are sized against
their node instead of their limit.

## Checklist

- [ ] The container has its own memory limit, or `QUEUE_AUTOSCALE_MEMORY_LIMIT_MB` is set
- [ ] `QUEUE_AUTOSCALE_MEMORY_LIMIT_MB` excludes memory used by sidecars in the same pod or task
- [ ] `-vvv` output shows `(cgroup)` or `(configured)`, not `(host)`
- [ ] `limits.worker_memory_mb_estimate` close to your workers' measured RSS
- [ ] `terminationGracePeriodSeconds` / ECS `stopTimeout` at least the longest
      `workers.shutdown_timeout_seconds` — see [Docker / Compose](docker.md)
