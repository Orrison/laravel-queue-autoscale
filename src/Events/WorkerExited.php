<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkerExited
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

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
