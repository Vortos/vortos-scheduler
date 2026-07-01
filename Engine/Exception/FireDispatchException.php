<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Engine\Exception;

use RuntimeException;
use Vortos\Scheduler\Fire\ScheduledFire;

/**
 * Thrown by FireDispatcher when an unexpected error prevents dispatch —
 * distinct from DuplicateSlotException (expected, idempotent no-op) or
 * SkippedOverlap (expected, policy decision).
 *
 * The caller (SchedulerDaemon, S5) should catch this, log it, and continue
 * to the next schedule rather than crashing the daemon.
 */
final class FireDispatchException extends RuntimeException
{
    public function __construct(
        public readonly ScheduledFire $fire,
        string                        $reason,
        ?\Throwable                   $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'FireDispatcher: dispatch failed for schedule=%s slot=%s — %s',
                $fire->scheduleId->toString(),
                $fire->slot,
                $reason,
            ),
            previous: $previous,
        );
    }
}
