<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Store\Exception;

/**
 * Thrown by ScheduleStoreInterface::save() when the stored version does not match
 * the caller's version — a concurrent writer committed first.
 *
 * The caller should re-fetch, re-apply their mutation, and retry.
 */
final class OptimisticLockException extends \DomainException
{
    public function __construct(string $scheduleId, int $expectedVersion)
    {
        parent::__construct(
            "Optimistic lock conflict on schedule '{$scheduleId}': " .
            "expected version {$expectedVersion} but a concurrent write changed it.",
        );
    }
}
