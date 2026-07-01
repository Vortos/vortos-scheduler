<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Store\Dbal;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Vortos\Scheduler\Store\RunRetentionOverride;
use Vortos\Scheduler\Store\RunRetentionOverrideStoreInterface;

final class DbalRunRetentionOverrideStore implements RunRetentionOverrideStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string     $table,
    ) {}

    public function save(RunRetentionOverride $override): void
    {
        $isPostgres = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;

        if ($isPostgres) {
            $this->connection->executeStatement(
                "INSERT INTO {$this->table} (tenant_id, retention_days, actor_id, updated_at)
                 VALUES (?, ?, ?, ?)
                 ON CONFLICT (tenant_id) DO UPDATE SET
                   retention_days = EXCLUDED.retention_days,
                   actor_id       = EXCLUDED.actor_id,
                   updated_at     = EXCLUDED.updated_at",
                [
                    $override->tenantId,
                    $override->retentionDays,
                    $override->actorId,
                    $override->updatedAt->format(\DateTimeInterface::ATOM),
                ],
            );
        } else {
            $this->connection->executeStatement(
                "INSERT OR REPLACE INTO {$this->table} (tenant_id, retention_days, actor_id, updated_at)
                 VALUES (?, ?, ?, ?)",
                [
                    $override->tenantId,
                    $override->retentionDays,
                    $override->actorId,
                    $override->updatedAt->format(\DateTimeInterface::ATOM),
                ],
            );
        }
    }

    public function find(string $tenantId): ?RunRetentionOverride
    {
        $row = $this->connection->fetchAssociative(
            "SELECT * FROM {$this->table} WHERE tenant_id = ?",
            [$tenantId],
        );

        return $row !== false ? $this->fromRow($row) : null;
    }

    public function remove(string $tenantId): void
    {
        $this->connection->executeStatement(
            "DELETE FROM {$this->table} WHERE tenant_id = ?",
            [$tenantId],
        );
    }

    public function findAll(): array
    {
        $rows = $this->connection->fetchAllAssociative("SELECT * FROM {$this->table}");

        return array_map($this->fromRow(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private function fromRow(array $row): RunRetentionOverride
    {
        return new RunRetentionOverride(
            tenantId:      (string) $row['tenant_id'],
            retentionDays: (int) $row['retention_days'],
            actorId:       (string) $row['actor_id'],
            updatedAt:     new DateTimeImmutable((string) $row['updated_at'], new DateTimeZone('UTC')),
        );
    }
}
