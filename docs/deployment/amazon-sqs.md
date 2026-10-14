---
title: "Running on Amazon SQS"
description: "Autoscale queue workers on Amazon SQS: queue names and URLs, suffixes, FIFO queues, pickup-time samples and LocalStack"
weight: 6
---

# Running on Amazon SQS

The autoscaler works on SQS like on any other driver: the manager reads queue depth through
`laravel-queue-metrics`, spawns `queue:work sqs` workers, and terminates them when the backlog is
gone. Two things differ from Redis, and both decide how well it scales:

- **SQS cannot report the age of its oldest message.** The pickup-time SLA — for scaling and for
  `SlaBreached` / `SlaRecovered` alike — has to come from pickup samples, and on SQS those need two
  settings that are off by default.
- **Workers see a queue URL, not a queue name.** The package reduces it back to the name, which is
  what you configure — so keep your queue names free of the connection's suffix.

Version 4.4 or later is assumed throughout. Before 4.4, pickup samples and failure-fuse outcomes on
SQS were recorded under the queue URL and never reached the manager. On 4.4, samples reached the manager
and drove scaling, but `SlaBreached` and `SlaRecovered` still could not fire on SQS; that needs 4.5.

## The minimal setup

```php
// config/queue.php
'sqs' => [
    'driver' => 'sqs',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'prefix' => env('SQS_PREFIX', 'https://sqs.eu-west-1.amazonaws.com/123456789012'),
    'queue' => env('SQS_QUEUE', 'default'),
    'suffix' => env('SQS_SUFFIX'),
    'region' => env('AWS_DEFAULT_REGION', 'eu-west-1'),
],
```

```php
// config/queue-autoscale.php
'queues' => [
    'emails' => [
        'connection' => 'sqs',
        'sla' => ['target_seconds' => 60],
        'workers' => ['min' => 1, 'max' => 20],
    ],
],
```

```bash
# .env
QUEUE_CONNECTION=sqs

# Pickup-time samples: stamp pushedAt and keep the samples in Redis
QUEUE_AUTOSCALE_STAMP_PUSHED_AT=true
QUEUE_AUTOSCALE_PICKUP_TIME_STORE=redis
```

The queue key in `queue-autoscale.queues` is the queue **name** — the same string you dispatch to
and that `queue:work --queue=` receives — never the URL. The `connection` key tells the manager to
read that queue's metrics from, and spawn its workers on, the `sqs` connection.

## Why pickup samples matter on SQS

The SLA is a pickup-time target: how long a job waits between becoming available and a worker
starting it. The strategy reads it from two sources, in order:

1. **The p95 of recent pickup samples**, recorded by every worker as it starts a job.
2. **The oldest pending job's age**, read from the queue driver.

SQS has no API for the second — Laravel's `SqsQueue::creationTimeOfOldestPendingJob()` returns
`null` — so on SQS the samples are the only SLA signal. Without them:

- Worker targets come from the arrival rate (Little's Law) and the current backlog drained within the
  SLA window. Nothing measures how long jobs are actually waiting, so there is no urgency as they
  approach the target.
- `SlaBreached` and `SlaRecovered` never fire. Breach is judged on the same signal the strategy
  scales on — the pickup percentile when there are enough samples, the oldest job's age otherwise —
  and on SQS that age is always 0. `SlaBreachPredicted` still fires: its prediction is the time to
  work through the backlog at the target worker count, which needs no job age.

With samples flowing, from 4.5 `SlaBreached` fires when the p95 pickup time reaches the queue's
`sla.target_seconds`, and `SlaRecovered` when the p95 over `sla.window_seconds` is back under it.
The events' `oldestJobAge` / `currentJobAge` stay 0 on SQS; read `$event->slaSignal` or
`$event->signalSeconds()` for the pickup time the breach was decided on. See
[How breach is decided](../basic-usage/monitoring.md#how-breach-is-decided) for the window and
recovery semantics.

A sample needs two things:

- **`pushedAt` in the job payload.** Horizon writes it, but Horizon only runs on Redis, so on SQS
  set `QUEUE_AUTOSCALE_STAMP_PUSHED_AT=true`. The package then stamps `pushedAt` on every job
  dispatched from the application. Stamping is safe with the required `laravel-queue-metrics`
  3.4.1, which measures job duration from when processing starts, not from `pushedAt`.
- **A shared store.** Samples are recorded inside the worker processes and read by the manager.
  The default store, `auto`, is Redis in cluster mode and a no-op on a single host, so a single
  host needs `QUEUE_AUTOSCALE_PICKUP_TIME_STORE=redis`. Redis is used only for these samples; the
  jobs stay on SQS.

Only a job's first attempt is sampled, a delayed job is measured from when its delay ends, and
samples above `pickup_time.max_sample_seconds` (default 3600) are dropped. Keep that cap well above
your SLA targets; `queue:autoscale:doctor` warns when it is not. See
[Where pickup time comes from](../basic-usage/configuration.md#where-pickup-time-comes-from).

To check that samples arrive, run the manager with `-vv` while the queue has a backlog. When the
backlog drives the decision, its reason names the SLA signal it used: `p95=…` once enough samples
exist (`sla.min_samples`), `oldest_age=0.0s` while they do not.

```bash
php artisan queue:autoscale -vv
```

## Queue names, URLs and the suffix

Laravel's SQS driver builds a queue URL from the connection's `prefix`, the queue name and the
`suffix`, and hands workers that full URL as the job's queue. Dispatching, `queue:work --queue`, the
autoscaler's configuration and the metrics package all use the name, so anything recorded inside a
worker — pickup samples and failure-fuse outcomes — is first reduced back to it:

1. A queue value starting with `http://` or `https://` (any case) is treated as a URL. Anything else
   is already a name and is left alone.
2. The last segment of the URL's path is taken. A URL with no path segment is left as it is.
3. If the connection has a `suffix`, it is removed from the end of the name. SQS FIFO names keep
   their `.fifo`, and the suffix is removed from before it, the way Laravel adds it.

| Connection `suffix` | Queue URL a worker sees | Recorded as |
|---|---|---|
| none | `https://sqs.eu-west-1.amazonaws.com/123456789012/emails` | `emails` |
| `-production` | `https://sqs.eu-west-1.amazonaws.com/123456789012/emails-production` | `emails` |
| `-production` | `https://sqs.eu-west-1.amazonaws.com/123456789012/orders-production.fifo` | `orders.fifo` |
| `-production` | `https://sqs.eu-west-1.amazonaws.com/123456789012/-production` | `-production` (a name that is only the suffix is kept) |
| none | `http://local_stack:4566/000000000000/emails` | `emails` |

`laravel-queue-metrics` 3.4.1 applies the same rule, so depth, throughput, pickup samples and fuse
outcomes all meet under one name, and `excluded` patterns match SQS queues by name.

**Do not include the suffix in the queue name itself.** Laravel only appends the suffix when the
name does not already end with it, so a queue configured as `emails-production` on a connection
with the suffix `-production` is sent to `…/emails-production` — and recorded as `emails`, which is
not the name the manager reads. Configure and dispatch to `emails`, and let the suffix do its job.

SQS only accepts letters, digits, hyphens and underscores in a queue name, plus a `.fifo` ending.
A dotted name such as `tenant.42` works on Redis and fails at dispatch on SQS;
`queue:autoscale:doctor` flags it when an SQS connection is configured.

## FIFO queues

FIFO queues scale like standard ones, with two differences:

- A FIFO queue releases at most one message per message group at a time, so workers beyond the
  number of active groups only poll. `queue:autoscale:doctor` notes FIFO queues whose `workers.max`
  allows more than one worker. See [Scaling FIFO Queues](../cookbook/fifo-queues.md).
- SQS does not accept a per-message delay on a FIFO queue, so Laravel dispatches delayed FIFO jobs
  without one. Pickup is still measured from `pushedAt`.

Configure the queue under its full name, `.fifo` included:

```php
'queues' => [
    'orders.fifo' => [
        'connection' => 'sqs',
        'workers' => ['min' => 1, 'max' => 5],
    ],
],
```

## Depth is approximate

The backlog the default strategy works from is SQS's `ApproximateNumberOfMessages`, which AWS
describes as approximate and eventually consistent. Small queues can read a message or two off for a short while, which can delay
a scale-to-zero by a cycle. Messages in flight (received but not deleted) are not part of the
backlog, so a long visibility timeout does not inflate it.

## LocalStack and ElasticMQ

Point the connection's `prefix` at the emulator. Hosts with underscores, such as a Docker Compose
service named `local_stack`, are recognised as queue URLs from 4.4:

```php
'sqs' => [
    'driver' => 'sqs',
    'key' => 'test',
    'secret' => 'test',
    'prefix' => env('SQS_PREFIX', 'http://local_stack:4566/000000000000'),
    'queue' => 'default',
    'region' => 'us-east-1',
    'endpoint' => env('SQS_ENDPOINT', 'http://local_stack:4566'),
],
```

The package's own SQS specs run against [ElasticMQ](https://github.com/softwaremill/elasticmq); see
[Requirements](../requirements.md) for the container they use.

## Workers that hold long-running jobs

On SQS a job that runs longer than the queue's visibility timeout is delivered again to another
worker. Keep `workers.timeout_seconds` (the `--timeout` passed to `queue:work`) below the queue's
visibility timeout. A job that exceeds `--timeout` is SIGKILLed by `queue:work` itself and shows up
as a `WorkerExited` with signal 9 — see
[Troubleshooting](../basic-usage/troubleshooting.md#workers-spawn-but-die-within-seconds).

## Checklist

- [ ] Queue keys in `queue-autoscale.queues` are queue names with `'connection' => 'sqs'`
- [ ] No queue name already ends with the connection's `suffix`
- [ ] `QUEUE_AUTOSCALE_STAMP_PUSHED_AT=true`
- [ ] `QUEUE_AUTOSCALE_PICKUP_TIME_STORE=redis` on a single host (cluster mode uses Redis already)
- [ ] `workers.timeout_seconds` below each queue's visibility timeout
- [ ] `php artisan queue:autoscale:doctor` run, with no SQS name or pickup-cap warnings
