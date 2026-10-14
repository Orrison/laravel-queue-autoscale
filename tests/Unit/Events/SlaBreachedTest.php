<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Events\SlaBreached;
use Cbox\LaravelQueueAutoscale\Scaling\SlaSignal;
use Cbox\LaravelQueueAutoscale\Scaling\SlaSignalSource;

test('creates instance with all properties', function () {
    $event = new SlaBreached(
        connection: 'redis',
        queue: 'default',
        oldestJobAge: 45,
        slaTarget: 30,
        pending: 100,
        activeWorkers: 5,
    );

    expect($event->connection)->toBe('redis')
        ->and($event->queue)->toBe('default')
        ->and($event->oldestJobAge)->toBe(45)
        ->and($event->slaTarget)->toBe(30)
        ->and($event->pending)->toBe(100)
        ->and($event->activeWorkers)->toBe(5);
});

test('calculates breach seconds correctly', function () {
    $event = new SlaBreached(
        connection: 'redis',
        queue: 'default',
        oldestJobAge: 45,
        slaTarget: 30,
        pending: 100,
        activeWorkers: 5,
    );

    expect($event->breachSeconds())->toBe(15);
});

test('breach seconds returns zero when not breached', function () {
    $event = new SlaBreached(
        connection: 'redis',
        queue: 'default',
        oldestJobAge: 20,
        slaTarget: 30,
        pending: 100,
        activeWorkers: 5,
    );

    expect($event->breachSeconds())->toBe(0);
});

test('calculates breach percentage correctly', function () {
    $event = new SlaBreached(
        connection: 'redis',
        queue: 'default',
        oldestJobAge: 45,
        slaTarget: 30,
        pending: 100,
        activeWorkers: 5,
    );

    expect($event->breachPercentage())->toBe(50.0);
});

test('breach percentage handles zero sla target', function () {
    $event = new SlaBreached(
        connection: 'redis',
        queue: 'default',
        oldestJobAge: 45,
        slaTarget: 0,
        pending: 100,
        activeWorkers: 5,
    );

    expect($event->breachPercentage())->toBe(0.0);
});

test('breach percentage handles exact sla match', function () {
    $event = new SlaBreached(
        connection: 'redis',
        queue: 'default',
        oldestJobAge: 30,
        slaTarget: 30,
        pending: 100,
        activeWorkers: 5,
    );

    expect($event->breachPercentage())->toBe(0.0);
});

test('an event built without a signal keeps its old shape and arithmetic', function () {
    $event = new SlaBreached(
        connection: 'redis',
        queue: 'default',
        oldestJobAge: 45,
        slaTarget: 30,
        pending: 100,
        activeWorkers: 5,
    );

    expect($event->slaSignal)->toBeNull()
        ->and($event->signalSeconds())->toBe(45.0)
        ->and($event->breachSeconds())->toBe(15)
        ->and($event->breachPercentage())->toBe(50.0);
});

test('an SQS breach is measured from the pickup percentile it was decided on', function () {
    // SQS cannot report the oldest age, so it is 0; the signal carries the
    // number the breach was actually decided on.
    $event = new SlaBreached(
        connection: 'sqs',
        queue: 'orders',
        oldestJobAge: 0,
        slaTarget: 30,
        pending: 200,
        activeWorkers: 2,
        slaSignal: SlaSignal::fromPickupPercentile(45.0, 95),
    );

    expect($event->oldestJobAge)->toBe(0)
        ->and($event->slaSignal?->source)->toBe(SlaSignalSource::PickupPercentile)
        ->and($event->slaSignal?->percentile)->toBe(95)
        ->and($event->signalSeconds())->toBe(45.0)
        ->and($event->breachSeconds())->toBe(15)
        ->and($event->breachPercentage())->toBe(50.0);
});

test('a fractional overshoot rounds breach seconds up, so a breach is never reported as zero', function () {
    $event = new SlaBreached(
        connection: 'sqs',
        queue: 'orders',
        oldestJobAge: 0,
        slaTarget: 30,
        pending: 1,
        activeWorkers: 1,
        slaSignal: SlaSignal::fromPickupPercentile(30.2, 95),
    );

    expect($event->breachSeconds())->toBe(1);
});

test('the event survives serialization with its signal', function () {
    $event = new SlaBreached('sqs', 'orders', 0, 30, 200, 2, SlaSignal::fromPickupPercentile(45.0, 95));

    $restored = unserialize(serialize($event));

    expect($restored->slaSignal)->toEqual(SlaSignal::fromPickupPercentile(45.0, 95));
});
