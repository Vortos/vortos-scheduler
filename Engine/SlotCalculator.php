<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Engine;

use DateTimeImmutable;
use DateTimeZone;
use Vortos\Scheduler\Fire\IdempotencyKey;
use Vortos\Scheduler\Schedule\ScheduleId;

/**
 * Maps a (scheduleId, scheduledFor, timezone) triple to a deterministic slot key.
 *
 * Slot key format: "<scheduleId>:<ISO-8601-with-offset-in-schedule-TZ>"
 * Example: "01927a3b-1234-7abc-8def-000000000001:2026-06-30T02:00:00+10:00"
 *
 * The UTC offset in the slot key is the schedule-TZ offset at that instant (DST-aware).
 * Two daemons computing the same UTC instant in the same timezone get the same offset
 * and therefore the same slot key — the fire-ledger unique constraint collapses any race
 * to a single enqueue, regardless of lease backend state.
 *
 * On DST fall-back days, 2:30am AEDT (+11:00) and 2:30am AEST (+10:00) produce different
 * slot keys, ensuring they are treated as distinct fire opportunities. The cron trigger
 * naturally only produces one of them per evaluation (no double-fire at the trigger level).
 */
final class SlotCalculator
{
    /**
     * Human-readable slot key stored in the fire-ledger for diagnostics.
     */
    public function slotKey(
        ScheduleId        $scheduleId,
        DateTimeImmutable $scheduledFor,
        DateTimeZone      $timezone,
    ): string {
        $inTz = $scheduledFor->setTimezone($timezone);

        // format('c') = ISO 8601 with UTC offset, e.g. "2026-06-30T02:00:00+10:00".
        return $scheduleId->toString() . ':' . $inTz->format('c');
    }

    /**
     * Fixed-length sha256 of the slot key — the value stored in the unique constraint.
     */
    public function idempotencyKey(
        ScheduleId        $scheduleId,
        DateTimeImmutable $scheduledFor,
        DateTimeZone      $timezone,
    ): IdempotencyKey {
        return IdempotencyKey::fromSlotKey(
            $this->slotKey($scheduleId, $scheduledFor, $timezone)
        );
    }
}
