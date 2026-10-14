<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;
use Cbox\LaravelQueueAutoscale\Pickup\PickupSampler;
use Cbox\LaravelQueueAutoscale\Pickup\PickupTimeRecorder;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;

/**
 * @param  array<int, array<string, mixed>>  $recorded
 */
function recordingStore(array &$recorded): PickupTimeStoreContract
{
    return new class($recorded) implements PickupTimeStoreContract
    {
        /**
         * @param  array<int, array<string, mixed>>  $recorded
         */
        public function __construct(private array &$recorded) {}

        public function record(string $connection, string $queue, float $timestamp, float $pickupSeconds): void
        {
            $this->recorded[] = compact('connection', 'queue', 'timestamp', 'pickupSeconds');
        }

        public function recentSamples(string $connection, string $queue, int $windowSeconds): array
        {
            return [];
        }
    };
}

function jobProcessing(mixed $pushedAt, ?string $queue = 'default', int $attempts = 1, ?int $delay = null): JobProcessing
{
    $payload = $pushedAt === null ? ['id' => 'abc'] : ['pushedAt' => $pushedAt, 'delay' => $delay];

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn($payload);
    $job->shouldReceive('getQueue')->andReturn($queue);
    $job->shouldReceive('attempts')->andReturn($attempts);

    return new JobProcessing('redis', $job);
}

function recorderWith(PickupTimeStoreContract $store, ?PickupSampler $sampler = null): PickupTimeRecorder
{
    return new PickupTimeRecorder($store, $sampler ?? new PickupSampler(enabled: false));
}

test('records pickup time derived from payload pushedAt', function (): void {
    $recorded = [];
    $recorder = recorderWith(recordingStore($recorded));

    $recorder->handle(jobProcessing(microtime(true) - 2.0));

    expect($recorded)->toHaveCount(1);
    expect($recorded[0]['connection'])->toBe('redis');
    expect($recorded[0]['queue'])->toBe('default');
    expect($recorded[0]['pickupSeconds'])->toBeGreaterThan(1.9)->toBeLessThan(2.1);
});

test('silently skips when pushedAt is absent from payload', function (): void {
    $recorded = [];
    $recorder = recorderWith(recordingStore($recorded));

    $recorder->handle(jobProcessing(null));

    expect($recorded)->toHaveCount(0);
});

test('uses default queue name when none provided', function (): void {
    $recorded = [];
    $recorder = recorderWith(recordingStore($recorded));

    $recorder->handle(jobProcessing(microtime(true) - 1.0, queue: null));

    expect($recorded[0]['queue'])->toBe('default');
});

test('a declining sampler keeps the write off the hot path', function (): void {
    // A mutation that deleted the sampler check entirely used to survive the
    // suite, because this spec configured maxPerSecond: 0 — which disables
    // sampling — and then asserted the write happened. It proved the opposite
    // of its name. The sampler must actually decline.
    $recorded = [];
    $now = 1000.0;

    // Prime a busy window, then roll past it so the next one is sampled hard.
    $rolling = new PickupSampler(
        enabled: true,
        maxPerSecond: 1,
        clock: function () use (&$now): float {
            return $now;
        },
        randomizer: fn (): float => 0.99,
    );
    for ($i = 0; $i < 500; $i++) {
        $rolling->shouldRecord('redis', 'default');
    }
    $now += 1.0;

    $recorder = recorderWith(recordingStore($recorded), $rolling);
    $recorder->handle(jobProcessing(microtime(true) - 1.0));

    expect($recorded)->toHaveCount(0);
});

test('a non-positive rate disables sampling rather than discarding everything', function (): void {
    // Misconfiguration must fail toward recording. Split out from the spec
    // above, which used to conflate the two.
    $recorded = [];
    $recorder = recorderWith(
        recordingStore($recorded),
        new PickupSampler(enabled: true, maxPerSecond: 0, randomizer: fn (): float => 0.99),
    );

    $recorder->handle(jobProcessing(microtime(true) - 1.0));

    expect($recorded)->toHaveCount(1);
});

test('a job with no pushedAt does not consume sampling budget', function (): void {
    // Order matters: if the sampler ran first, unparseable jobs would burn the
    // budget and starve the samples that actually carry a pickup time.
    $recorded = [];
    $sampler = new PickupSampler(enabled: true, maxPerSecond: 1);
    $recorder = recorderWith(recordingStore($recorded), $sampler);

    for ($i = 0; $i < 50; $i++) {
        $recorder->handle(jobProcessing(null));
    }

    expect($sampler->currentSampleRate())->toBe(1.0);
});

test('measures a delayed job from when it became available, not from dispatch', function (): void {
    $recorded = [];
    $recorder = recorderWith(recordingStore($recorded));

    $recorder->handle(jobProcessing(microtime(true) - 601.0, delay: 600));

    expect($recorded[0]['pickupSeconds'])->toBeGreaterThan(0.9)->toBeLessThan(1.1);
});

test('never records a negative pickup for a job run before its delay elapsed', function (): void {
    // Clock skew between dispatcher and worker; never a negative pickup.
    $recorded = [];
    $recorder = recorderWith(recordingStore($recorded));

    $recorder->handle(jobProcessing(microtime(true), delay: 60));

    expect($recorded[0]['pickupSeconds'])->toBe(0.0);
});

test('skips attempts after the first, which keep the first push\'s pushedAt', function (): void {
    $recorded = [];
    $recorder = recorderWith(recordingStore($recorded));

    $recorder->handle(jobProcessing(microtime(true) - 120.0, attempts: 2));

    expect($recorded)->toHaveCount(0);
});

test('drops a sample above the configured maximum', function (): void {
    // A failed job re-pushed with queue:retry starts again at attempt 1 but
    // keeps its original pushedAt.
    config()->set('queue-autoscale.pickup_time.max_sample_seconds', 3600);
    $recorded = [];
    $recorder = recorderWith(recordingStore($recorded));

    $recorder->handle(jobProcessing(microtime(true) - 86_400.0));
    $recorder->handle(jobProcessing(microtime(true) - 3_000.0));

    expect($recorded)->toHaveCount(1);
    expect($recorded[0]['pickupSeconds'])->toBeGreaterThan(2_999.0);
});

test('keeps every sample when the maximum is disabled', function (): void {
    config()->set('queue-autoscale.pickup_time.max_sample_seconds', null);
    $recorded = [];
    $recorder = recorderWith(recordingStore($recorded));

    $recorder->handle(jobProcessing(microtime(true) - 86_400.0));

    expect($recorded)->toHaveCount(1);
});

test('records an SQS job under the queue name, not the queue URL', function (): void {
    // SqsJob::getQueue() is the full URL; the manager reads samples by name.
    $recorded = [];
    $recorder = recorderWith(recordingStore($recorded));

    $recorder->handle(jobProcessing(microtime(true) - 1.0, queue: 'https://sqs.us-east-1.amazonaws.com/123456789012/emails'));

    expect($recorded[0]['queue'])->toBe('emails');
});

test('a maximum of zero or below disables the cap instead of dropping every sample', function (int $max): void {
    config()->set('queue-autoscale.pickup_time.max_sample_seconds', $max);
    $recorded = [];
    $recorder = recorderWith(recordingStore($recorded));

    $recorder->handle(jobProcessing(microtime(true) - 5.0));

    expect($recorded)->toHaveCount(1);
})->with(['zero' => [0], 'negative' => [-1]]);

test('the cap applies to time since the job became available, not since dispatch', function (): void {
    // Pushed two hours ago with a two-hour delay: available a second ago.
    config()->set('queue-autoscale.pickup_time.max_sample_seconds', 3600);
    $recorded = [];
    $recorder = recorderWith(recordingStore($recorded));

    $recorder->handle(jobProcessing(microtime(true) - 7_201.0, delay: 7_200));

    expect($recorded)->toHaveCount(1)
        ->and($recorded[0]['pickupSeconds'])->toBeLessThan(2.0);
});

test('a numeric-string pushedAt is accepted', function (): void {
    $recorded = [];
    $recorder = recorderWith(recordingStore($recorded));

    $recorder->handle(jobProcessing((string) (microtime(true) - 3.0)));

    expect($recorded)->toHaveCount(1)
        ->and($recorded[0]['pickupSeconds'])->toBeGreaterThan(2.9)->toBeLessThan(3.1);
});

test('a non-numeric pushedAt is skipped', function (): void {
    $recorded = [];
    $recorder = recorderWith(recordingStore($recorded));

    $recorder->handle(jobProcessing('yesterday'));

    expect($recorded)->toHaveCount(0);
});

test('records an SQS job under the name without the connection suffix', function (): void {
    config()->set('queue.connections.redis.suffix', '-production');
    $recorded = [];
    $recorder = recorderWith(recordingStore($recorded));

    // jobProcessing() fires on the 'redis' connection, so its suffix applies.
    $recorder->handle(jobProcessing(microtime(true) - 1.0, queue: 'https://sqs.us-east-1.amazonaws.com/123456789012/orders-production.fifo'));

    expect($recorded[0]['queue'])->toBe('orders.fifo');
});
