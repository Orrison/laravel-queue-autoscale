<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Support;

use Illuminate\Support\Str;

/**
 * The queue name a running job belongs to.
 *
 * Laravel's SQS driver hands workers the full queue URL as the job's queue
 * (`SqsJob::getQueue()`), while dispatching, `queue:work --queue`, the
 * configuration and the metrics package all use the queue name. Anything
 * recorded from inside a worker is keyed by the name so the manager, which
 * reads by name, finds it.
 */
class QueueName
{
    public static function normalize(string $connection, string $queue): string
    {
        if (! self::isUrl($queue)) {
            return $queue;
        }

        $path = parse_url($queue, PHP_URL_PATH);
        $name = is_string($path) ? basename($path) : '';

        if ($name === '') {
            return $queue;
        }

        return self::withoutSuffix($name, config("queue.connections.{$connection}.suffix"));
    }

    /**
     * An http(s) scheme prefix, compared case-insensitively, is enough.
     *
     * FILTER_VALIDATE_URL is stricter than SQS endpoints in practice: it
     * rejects host names with underscores, which Docker Compose service names
     * routinely have (`http://local_stack:4566/000000000000/emails`), so a
     * LocalStack queue URL was never reduced to its name. Queue names do not
     * start with a scheme, and parse_url() still has to find a path below.
     */
    private static function isUrl(string $queue): bool
    {
        return str_starts_with(strtolower($queue), 'https://') || str_starts_with(strtolower($queue), 'http://');
    }

    /**
     * Inverts SqsQueue::suffixQueue(), which places the suffix before `.fifo`.
     */
    private static function withoutSuffix(string $name, mixed $suffix): string
    {
        if (! is_string($suffix) || $suffix === '') {
            return $name;
        }

        $fifo = str_ends_with($name, '.fifo') ? '.fifo' : '';
        $base = $fifo === '' ? $name : Str::beforeLast($name, '.fifo');

        if (! str_ends_with($base, $suffix) || $base === $suffix) {
            return $name;
        }

        return substr($base, 0, -strlen($suffix)).$fifo;
    }
}
