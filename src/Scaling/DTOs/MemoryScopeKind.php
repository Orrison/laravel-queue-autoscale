<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\DTOs;

/**
 * Which limit a memory reading is measured against.
 *
 * The backing values are the strings `memory_details.memory_scope` and
 * `memory_details.memory_capped_by` have always carried, which consumers read.
 */
enum MemoryScopeKind: string
{
    /** `limits.memory_limit_mb`, for a limit the process cannot see. */
    case Configured = 'configured';

    /** The container's own cgroup limit. */
    case Cgroup = 'cgroup';

    /** The kernel's MemTotal and MemAvailable. */
    case Host = 'host';

    /** Nothing readable: half of the reported memory assumed in use. */
    case Fallback = 'fallback';

    /**
     * Most specific first: `max_memory_percent` applies to the first of these
     * that was measured.
     *
     * @return list<self>
     */
    public static function byPrecedence(): array
    {
        return [self::Configured, self::Cgroup, self::Host, self::Fallback];
    }
}
