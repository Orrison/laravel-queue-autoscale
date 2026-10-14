<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\DTOs;

/**
 * A memory limit paired with the usage measured against that same limit, in MB.
 */
readonly class MemoryScope
{
    public function __construct(
        public MemoryScopeKind $kind,
        public float $limitMb,
        public float $usedMb,
    ) {}

    public function freeMb(): float
    {
        return $this->limitMb - $this->usedMb;
    }
}
