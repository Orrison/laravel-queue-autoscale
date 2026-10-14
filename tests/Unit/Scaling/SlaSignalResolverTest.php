<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\GroupConfiguration;
use Cbox\LaravelQueueAutoscale\Pickup\SortBasedPercentileCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\SlaSignalResolver;
use Cbox\LaravelQueueAutoscale\Scaling\SlaSignalSource;
use Cbox\LaravelQueueAutoscale\Testing\InMemoryPickupTimeStore;
use Cbox\LaravelQueueAutoscale\Testing\QueueMetricsFactory;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00'));

    $this->store = new InMemoryPickupTimeStore;
    $this->resolver = new SlaSignalResolver($this->store, new SortBasedPercentileCalculator);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('with enough samples the signal is the configured percentile of pickup times', function (): void {
    // 19 fast pickups and one slow: p95 of twenty lands on the 19th value.
    $this->store->seed([...array_fill(0, 19, 2.0), 90.0]);

    $signal = $this->resolver->resolve(QueueMetricsFactory::idle(), makeQueueConfig());

    expect($signal->source)->toBe(SlaSignalSource::PickupPercentile)
        ->and($signal->percentile)->toBe(95)
        ->and($signal->seconds)->toBe(2.0);
});

test('the percentile follows sla.percentile', function (): void {
    $this->store->seed([...array_fill(0, 19, 2.0), 90.0]);

    $signal = $this->resolver->resolve(QueueMetricsFactory::idle(), makeQueueConfig(['slaPercentile' => 99]));

    expect($signal->percentile)->toBe(99)
        ->and($signal->seconds)->toBe(90.0);
});

test('below sla.min_samples the oldest job age stands in, marked as a fallback', function (): void {
    $this->store->seedRepeated(19, 60.0);

    $signal = $this->resolver->resolve(QueueMetricsFactory::behind(10, oldestJobAge: 12), makeQueueConfig());

    expect($signal->source)->toBe(SlaSignalSource::OldestJobAgeFallback)
        ->and($signal->seconds)->toBe(12.0);
});

test('samples older than sla.window_seconds no longer count', function (): void {
    $this->store->seedRepeated(20, 60.0);

    Carbon::setTestNow(now()->addSeconds(299));
    expect($this->resolver->resolve(QueueMetricsFactory::idle(), makeQueueConfig())->seconds)->toBe(60.0);

    Carbon::setTestNow(now()->addSeconds(2));
    expect($this->resolver->resolve(QueueMetricsFactory::idle(), makeQueueConfig())->source)
        ->toBe(SlaSignalSource::OldestJobAgeFallback);
});

test('samples are read for the connection and queue being judged', function (): void {
    $this->store->seedRepeated(20, 60.0, 'redis', 'other');
    $this->store->seedRepeated(20, 60.0, 'sqs', 'default');

    expect($this->resolver->resolve(QueueMetricsFactory::idle(), makeQueueConfig())->source)
        ->toBe(SlaSignalSource::OldestJobAgeFallback);
});

test('a group pools the samples of every member queue', function (): void {
    $group = GroupConfiguration::fromConfig('notifications', [
        'connection' => 'redis',
        'queues' => ['mail', 'sms'],
        'overrides' => ['sla' => ['target_seconds' => 30, 'percentile' => 95, 'window_seconds' => 300, 'min_samples' => 10]],
    ]);

    $this->store->seedRepeated(5, 40.0, 'redis', 'mail');
    $this->store->seedRepeated(5, 40.0, 'redis', 'sms');

    $signal = $this->resolver->resolve(QueueMetricsFactory::idle('notifications'), $group->toScalingConfiguration());

    expect($signal->source)->toBe(SlaSignalSource::PickupPercentile)
        ->and($signal->seconds)->toBe(40.0);
});
