<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Store\Dbal;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Store\CadenceCursor;
use Vortos\Scheduler\Store\ScheduleCursorStoreInterface;

/**
 * DBAL driver for ScheduleCursorStoreInterface. PostgreSQL-targeted, DBAL-portable.
 *
 * Uses the write connection only — cadence advances must be read-after-write consistent within a
 * daemon tick. Optimistic concurrency guards concurrent advances from multiple nodes:
 *   expectedVersion = 0 → INSERT (fresh cursor); UNIQUE(schedule_id) makes a lost race return false
 *   expectedVersion > 0 → UPDATE ... WHERE cursor_version = expectedVersion (incremented in SQL)
 */
final class DbalScheduleCursorStore implements ScheduleCursorStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string     $table = 'vortos_scheduler_cursors',
    ) {}

    public function findCursors(array $scheduleIds, ?string $tenantId): array
    {
        if ($scheduleIds === []) {
            return [];
        }

        $ids          = array_map(static fn (ScheduleId $id): string => $id->toString(), $scheduleIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        if ($tenantId !== null) {
            $sql = "SELECT schedule_id, tenant_id, cursor_at, cursor_version
                    FROM {$this->table}
                    WHERE schedule_id IN ({$placeholders}) AND tenant_id = ?";
            $params = [...$ids, $tenantId];
        } else {
            $sql = "SELECT schedule_id, tenant_id, cursor_at, cursor_version
                    FROM {$this->table}
                    WHERE schedule_id IN ({$placeholders})";
            $params = $ids;
        }

        $rows   = $this->connection->fetchAllAssociative($sql, $params);
        $result = [];

        foreach ($rows as $row) {
            $scheduleId = (string) $row['schedule_id'];
            $result[$scheduleId] = new CadenceCursor(
                scheduleId: ScheduleId::fromString($scheduleId),
                tenantId:   $row['tenant_id'] !== null ? (string) $row['tenant_id'] : null,
                cursorAt:   new DateTimeImmutable((string) $row['cursor_at'], new DateTimeZone('UTC')),
                version:    (int) $row['cursor_version'],
            );
        }

        return $result;
    }

    public function advance(
        ScheduleId        $id,
        ?string           $tenantId,
        DateTimeImmutable $newCursor,
        int               $expectedVersion,
    ): bool {
        $cursorAt = $newCursor->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $now      = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        if ($expectedVersion === 0) {
            try {
                $this->connection->insert($this->table, [
                    'schedule_id'    => $id->toString(),
                    'tenant_id'      => $tenantId,
                    'cursor_at'      => $cursorAt,
                    'cursor_version' => 1,
                    'updated_at'     => $now,
                ]);

                return true;
            } catch (UniqueConstraintViolationException) {
                // Another node inserted the cursor first — lost race, reconcile next tick.
                return false;
            }
        }

        $affected = $this->connection->executeStatement(
            "UPDATE {$this->table}
             SET    cursor_at      = ?,
                    cursor_version = cursor_version + 1,
                    updated_at     = ?
             WHERE  schedule_id    = ?
               AND  cursor_version = ?",
            [$cursorAt, $now, $id->toString(), $expectedVersion],
        );

        return $affected === 1;
    }
}
