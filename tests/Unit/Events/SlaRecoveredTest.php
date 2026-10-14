<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Events\SlaRecovered;
use Cbox\LaravelQueueAutoscale\Scaling\SlaSignal;

test('creates instance with all properties', function () {
    $event = new SlaRecovered(
        connection: 'redis',
        queue: 'default',
        currentJobAge: 15,
        slaTarget: 30,
        pending: 50,
        activeWorkers: 8,
    );

    expect($event->connection)->toBe('redis')
        ->and($event->queue)->toBe('default')
        ->and($event->currentJobAge)->toBe(15)
        ->and($event->slaTarget)->toBe(30)
        ->and($event->pending)->toBe(50)
        ->and($event->activeWorkers)->toBe(8);
});

test('calculates margin seconds correctly', function () {
    $event = new SlaRecovered(
        connection: 'redis',
        queue: 'default',
        currentJobAge: 15,
        slaTarget: 30,
        pending: 50,
        activeWorkers: 8,
    );

    expect($event->marginSeconds())->toBe(15);
});

test('margin seconds returns zero when at sla target', function () {
    $event = new SlaRecovered(
        connection: 'redis',
        queue: 'default',
        currentJobAge: 30,
        slaTarget: 30,
        pending: 50,
        activeWorkers: 8,
    );

    expect($event->marginSeconds())->toBe(0);
});

test('margin seconds returns zero when over sla target', function () {
    $event = new SlaRecovered(
        connection: 'redis',
        queue: 'default',
        currentJobAge: 40,
        slaTarget: 30,
        pending: 50,
        activeWorkers: 8,
    );

    expect($event->marginSeconds())->toBe(0);
});

test('calculates margin percentage correctly', function () {
    $event = new SlaRecovered(
        connection: 'redis',
        queue: 'default',
        currentJobAge: 15,
        slaTarget: 30,
        pending: 50,
        activeWorkers: 8,
    );

    expect($event->marginPercentage())->toBe(50.0);
});

test('margin percentage handles zero sla target', function () {
    $event = new SlaRecovered(
        connection: 'redis',
        queue: 'default',
        currentJobAge: 15,
        slaTarget: 0,
        pending: 50,
        activeWorkers: 8,
    );

    expect($event->marginPercentage())->toBe(0.0);
});

test('margin percentage handles exact sla match', function () {
    $event = new SlaRecovered(
        connection: 'redis',
        queue: 'default',
        currentJobAge: 30,
        slaTarget: 30,
        pending: 50,
        activeWorkers: 8,
    );

    expect($event->marginPercentage())->toBe(0.0);
});

test('an event built without a signal keeps its old shape and arithmetic', function () {
    $event = new SlaRecovered(
        connection: 'redis',
        queue: 'default',
        currentJobAge: 15,
        slaTarget: 30,
        pending: 50,
        activeWorkers: 8,
    );

    expect($event->slaSignal)->toBeNull()
        ->and($event->signalSeconds())->toBe(15.0)
        ->and($event->marginSeconds())->toBe(15)
        ->and($event->marginPercentage())->toBe(50.0);
});

test('an SQS recovery is measured from the pickup percentile it was decided on', function () {
    $event = new SlaRecovered(
        connection: 'sqs',
        queue: 'orders',
        currentJobAge: 0,
        slaTarget: 30,
        pending: 4,
        activeWorkers: 3,
        slaSignal: SlaSignal::fromPickupPercentile(6.0, 95),
    );

    // Without the signal the margin would read a perfect 30s off an age SQS
    // cannot report.
    expect($event->currentJobAge)->toBe(0)
        ->and($event->signalSeconds())->toBe(6.0)
        ->and($event->marginSeconds())->toBe(24)
        ->and($event->marginPercentage())->toBe(80.0);
});

test('a fractional margin rounds down, so it is never overstated', function () {
    $event = new SlaRecovered('sqs', 'orders', 0, 30, 1, 1, SlaSignal::fromPickupPercentile(29.5, 95));

    expect($event->marginSeconds())->toBe(0);
});
