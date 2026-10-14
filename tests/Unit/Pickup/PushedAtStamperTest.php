<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Pickup\PushedAtStamper;
use Illuminate\Queue\SyncQueue;

/**
 * @return array<string, mixed>
 */
function dispatchedPayload(array $data = []): array
{
    $queue = new class extends SyncQueue
    {
        /**
         * @return array<string, mixed>
         */
        public function payloadFor(string $job, array $data): array
        {
            return json_decode($this->createPayload($job, 'default', $data), true);
        }
    };
    $queue->setContainer(app());
    $queue->setConnectionName('sync');

    return $queue->payloadFor('App\\Jobs\\Example', $data);
}

test('does not stamp pushedAt unless enabled', function (): void {
    expect(dispatchedPayload())->not->toHaveKey('pushedAt');
});

test('stamps pushedAt on dispatch when enabled', function (): void {
    config()->set('queue-autoscale.pickup_time.stamp_pushed_at', true);

    $before = microtime(true);
    $payload = dispatchedPayload();

    expect($payload['pushedAt'])->toBeFloat()->toBeGreaterThanOrEqual($before)->toBeLessThanOrEqual(microtime(true));
});

test('keeps a pushedAt the payload already carries', function (): void {
    config()->set('queue-autoscale.pickup_time.stamp_pushed_at', true);

    $stamped = (new PushedAtStamper)('redis', 'default', ['pushedAt' => '1700000000.5']);

    expect($stamped)->toBe([]);
});

test('returns nothing to merge when disabled, even for a payload without pushedAt', function (): void {
    config()->set('queue-autoscale.pickup_time.stamp_pushed_at', false);

    expect((new PushedAtStamper)('redis', 'default', ['uuid' => 'abc']))->toBe([]);
});

test('keeps a pushedAt of zero, which is set even though it is falsy', function (): void {
    config()->set('queue-autoscale.pickup_time.stamp_pushed_at', true);

    expect((new PushedAtStamper)('redis', 'default', ['pushedAt' => 0]))->toBe([]);
});

test('stamps a payload whose pushedAt key is present but null', function (): void {
    config()->set('queue-autoscale.pickup_time.stamp_pushed_at', true);

    $stamped = (new PushedAtStamper)('redis', 'default', ['pushedAt' => null]);

    expect($stamped)->toHaveKey('pushedAt')
        ->and($stamped['pushedAt'])->toBeFloat();
});

test('reads an env-style string flag as enabled', function (): void {
    config()->set('queue-autoscale.pickup_time.stamp_pushed_at', '1');

    expect(dispatchedPayload())->toHaveKey('pushedAt');
});
