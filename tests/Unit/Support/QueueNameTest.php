<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Support\QueueName;

test('an SQS queue URL is reduced to the queue name', function (string $queue, string $expected): void {
    expect(QueueName::normalize('sqs', $queue))->toBe($expected);
})->with([
    'standard queue' => ['https://sqs.us-east-1.amazonaws.com/123456789012/emails', 'emails'],
    'fifo queue' => ['https://sqs.us-east-1.amazonaws.com/123456789012/orders.fifo', 'orders.fifo'],
    'custom endpoint' => ['http://localhost:4566/000000000000/emails', 'emails'],
    'host name with an underscore' => ['http://local_stack:4566/000000000000/emails', 'emails'],
    'upper-case scheme' => ['HTTPS://sqs.us-east-1.amazonaws.com/123456789012/emails', 'emails'],
    'mixed-case scheme' => ['Http://localhost:4566/000000000000/orders.fifo', 'orders.fifo'],
]);

test('queue names that are not URLs are unchanged', function (string $queue): void {
    expect(QueueName::normalize('redis', $queue))->toBe($queue);
})->with(['default', 'reports:nightly', 'tenant/emails', 'http-callbacks']);

test('the suffix the SQS driver appends is removed', function (string $queue, string $expected): void {
    config()->set('queue.connections.sqs.suffix', '-production');

    expect(QueueName::normalize('sqs', $queue))->toBe($expected);
})->with([
    'standard queue' => ['https://sqs.us-east-1.amazonaws.com/123456789012/emails-production', 'emails'],
    'fifo queue' => ['https://sqs.us-east-1.amazonaws.com/123456789012/orders-production.fifo', 'orders.fifo'],
    'name without the suffix' => ['https://sqs.us-east-1.amazonaws.com/123456789012/emails', 'emails'],
]);

test('only the suffix of the job\'s own connection is removed', function (): void {
    config()->set('queue.connections.other.suffix', '-production');

    expect(QueueName::normalize('sqs', 'https://sqs.us-east-1.amazonaws.com/123456789012/emails-production'))
        ->toBe('emails-production');
});

test('a URL with no queue in its path is unchanged', function (): void {
    expect(QueueName::normalize('sqs', 'https://sqs.us-east-1.amazonaws.com/'))->toBe('https://sqs.us-east-1.amazonaws.com/');
});
