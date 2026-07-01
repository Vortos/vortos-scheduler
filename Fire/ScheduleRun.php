<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Fire;

use DateTimeImmutable;
use Vortos\Scheduler\Schedule\ScheduleId;

/**
 * Immutable record of a single scheduled fire as it exists in the run-ledger.
 *
 * The $runId is the IdempotencyKey value (sha256 hex string, 64 chars) and serves
 * as the primary key in the `scheduler_runs` table. Because sha256(slot) is globally
 * unique per (scheduleId, scheduledFor-in-TZ), no two distinct fires can share a runId.
 *
 * $slot is the human-readable slot key (scheduleId:ISO8601+offset) stored alongside
 * $runId for operator diagnostics — operators can inspect the table in psql without
 * decoding hashes.
 */
final readonly class ScheduleRun
{
    public function __construct(
        public string           $runId,          // IdempotencyKey->value (64 hex chars, PK)
        public ScheduleId       $scheduleId,
        public ?string          $tenantId,
        public string           $slot,           // human-readable: "scheduleId:ISO8601+offset"
        public DateTimeImmutable $scheduledFor,
        public DateTimeImmutable $dispatchedAt,
        public RunState         $state,
        public int              $attempt = 1,
    ) {}

    public function isTerminal(): bool
    {
        return $this->state->isTerminal();
    }
}
