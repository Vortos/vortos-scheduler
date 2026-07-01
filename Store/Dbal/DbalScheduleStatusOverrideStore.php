<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Store\Dbal;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Store\ScheduleStatusOverride;
use Vortos\Scheduler\Store\ScheduleStatusOverrideStoreInterface;

final class DbalScheduleStatusOverrideStore implements ScheduleStatusOverrideStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string     $table,
    ) {}

    public function save(ScheduleStatusOverride $override): void
    {
        $isPostgres = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;

        if ($isPostgres) {
            $this->connection->executeStatement(
                "INSERT INTO {$this->table} (schedule_id, status, actor_id, reason, updated_at)
                 VALUES (?, ?, ?, ?, ?)
                 ON CONFLICT (schedule_id) DO UPDATE SET
                   status     = EXCLUDED.status,
                   actor_id   = EXCLUDED.actor_id,
                   reason     = EXCLUDED.reason,
                   updated_at = EXCLUDED.updated_at",
                [
                    $override->scheduleId->toString(),
                    $override->status->value,
                    $override->actorId,
                    $override->reason,
                    $override->updatedAt->format(\DateTimeInterface::ATOM),
                ],
            );
        } else {
            $this->connection->executeStatement(
                "INSERT OR REPLACE INTO {$this->table} (schedule_id, status, actor_id, reason, updated_at)
                 VALUES (?, ?, ?, ?, ?)",
                [
                    $override->scheduleId->toString(),
                    $override->status->value,
                    $override->actorId,
                    $override->reason,
                    $override->updatedAt->format(\DateTimeInterface::ATOM),
                ],
            );
        }
    }

    public function find(ScheduleId $id): ?ScheduleStatusOverride
    {
        $row = $this->connection->fetchAssociative(
            "SELECT * FROM {$this->table} WHERE schedule_id = ?",
            [$id->toString()],
        );

        return $row !== false ? $this->fromRow($row) : null;
    }

    public function remove(ScheduleId $id): void
    {
        $this->connection->executeStatement(
            "DELETE FROM {$this->table} WHERE schedule_id = ?",
            [$id->toString()],
        );
    }

    public function findAllPaused(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT * FROM {$this->table} WHERE status = ?",
            [ScheduleStatus::Paused->value],
        );

        return array_map($this->fromRow(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private function fromRow(array $row): ScheduleStatusOverride
    {
        return new ScheduleStatusOverride(
            scheduleId: ScheduleId::fromString((string) $row['schedule_id']),
            status:     ScheduleStatus::from((string) $row['status']),
            actorId:    (string) $row['actor_id'],
            reason:     isset($row['reason']) && $row['reason'] !== null ? (string) $row['reason'] : null,
            updatedAt:  new DateTimeImmutable((string) $row['updated_at'], new DateTimeZone('UTC')),
        );
    }
}
