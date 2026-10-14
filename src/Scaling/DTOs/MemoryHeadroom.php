<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\DTOs;

/**
 * Memory headroom under the configured ceiling, before booting workers are
 * charged.
 *
 * `scope` is the limit `max_memory_percent` applied to; `cappedBy` names the
 * other scope whose free memory was lower, if any.
 */
readonly class MemoryHeadroom
{
    public function __construct(
        public MemoryScope $scope,
        public float $headroomMb,
        public ?MemoryScopeKind $cappedBy = null,
    ) {}
}
