<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Engine;

use Vortos\Scheduler\Fire\ScheduledFire;
use Vortos\Scheduler\Schedule\Schedule;

/**
 * PORT: abstracts the durable write that queues a scheduled command for
 * in-process dispatch by the SchedulerDaemon (S5).
 *
 * Implementations MUST NOT open their own transaction. FireDispatcher wraps
 * the ledger insert (insertRun) and this enqueue in a single BEGIN…COMMIT
 * so both writes are atomic.
 *
 * The fire queue is intentionally separate from messaging_outbox — scheduler
 * fires are in-process command dispatches, not broker-bound events.
 */
interface SchedulerEnqueuerPort
{
    /**
     * Persist a fire record to the scheduler fire queue.
     *
     * Called inside an active DBAL transaction (FireDispatcher's BEGIN…COMMIT).
     * Must not start its own transaction.
     *
     * The $fire.slot is used to derive the run_id, providing idempotency at the
     * queue level (unique constraint on run_id). A duplicate enqueue is a no-op
     * (the ledger UNIQUE constraint catches it first via DuplicateSlotException).
     *
     * @throws \RuntimeException on write failure (connection error, schema issue)
     */
    public function enqueue(ScheduledFire $fire, Schedule $schedule): void;
}
