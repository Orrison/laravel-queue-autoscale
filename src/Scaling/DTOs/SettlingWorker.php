<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\DTOs;

/**
 * A running worker spawned too recently to have grown to its memory
 * footprint, with the memory it holds right now where that can be read.
 */
readonly class SettlingWorker
{
    public function __construct(
        public ?int $pid = null,
        public ?float $residentMb = null,
    ) {}
}
