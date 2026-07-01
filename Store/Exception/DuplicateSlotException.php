<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Store\Exception;

use Vortos\Scheduler\Schedule\ScheduleId;

/**
 * Thrown by ScheduleRunStoreInterface::insertRun() when the UNIQUE constraint on
 * (tenant_id, schedule_id, slot) fires — i.e. this slot was already enqueued.
 *
 * This is the idempotency signal. FireDispatcher (S4) catches it and returns
 * early without double-enqueuing. It is NOT an error — it means exactly-once
 * semantics are working as designed.
 *
 * IMPORTANT: After catching this exception, the caller MUST call
 * $connection->rollBack() if an explicit transaction is open. PostgreSQL aborts
 * the transaction on a constraint violation; any further statements will fail
 * until the transaction is rolled back.
 */
final class DuplicateSlotException extends \DomainException
{
    public function __construct(
        public readonly string     $slot,
        public readonly ScheduleId $scheduleId,
        \Throwable                 $previous = null,
    ) {
        parent::__construct(
            "Slot '{$slot}' for schedule '{$scheduleId->toString()}' was already enqueued " .
            '(idempotency key already exists in the run-ledger).',
            0,
            $previous,
        );
    }
}
