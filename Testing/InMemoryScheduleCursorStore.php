<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Testing;

use DateTimeImmutable;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Store\CadenceCursor;
use Vortos\Scheduler\Store\ScheduleCursorStoreInterface;

/**
 * Pure in-memory ScheduleCursorStoreInterface for unit tests. Enforces the same CAS semantics as
 * the DBAL driver so daemon/engine tests exercise realistic advance/lost-race behaviour.
 */
final class InMemoryScheduleCursorStore implements ScheduleCursorStoreInterface
{
    /** @var array<string, CadenceCursor> keyed by scheduleId */
    private array $cursors = [];

    public function seed(ScheduleId $id, ?string $tenantId, DateTimeImmutable $cursorAt, int $version = 1): void
    {
        $this->cursors[$id->toString()] = new CadenceCursor($id, $tenantId, $cursorAt, $version);
    }

    public function findCursors(array $scheduleIds, ?string $tenantId): array
    {
        $result = [];
        foreach ($scheduleIds as $id) {
            $cursor = $this->cursors[$id->toString()] ?? null;
            if ($cursor === null) {
                continue;
            }
            if ($tenantId !== null && $cursor->tenantId !== $tenantId) {
                continue;
            }
            $result[$id->toString()] = $cursor;
        }

        return $result;
    }

    public function advance(
        ScheduleId        $id,
        ?string           $tenantId,
        DateTimeImmutable $newCursor,
        int               $expectedVersion,
    ): bool {
        $existing = $this->cursors[$id->toString()] ?? null;

        $now = new DateTimeImmutable('now');

        if ($expectedVersion === 0) {
            if ($existing !== null) {
                return false; // lost race — already inserted
            }
            $this->cursors[$id->toString()] = new CadenceCursor($id, $tenantId, $newCursor, 1, $now);

            return true;
        }

        if ($existing === null || $existing->version !== $expectedVersion) {
            return false;
        }

        $this->cursors[$id->toString()] = new CadenceCursor(
            $id,
            $tenantId,
            $newCursor,
            $existing->version + 1,
            // Mirrors the DBAL driver's COALESCE: preserved once set, filled in when it was never
            // recorded. This double left it null on every path, which is the divergence that let the
            // driver's own gap go unnoticed — the overdue check reads this field, and a permanently
            // null one is permanently unjudgeable.
            $existing->firstSeenAt ?? $now,
        );

        return true;
    }
}
