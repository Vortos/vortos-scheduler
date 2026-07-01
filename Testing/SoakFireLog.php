<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Testing;

use Doctrine\DBAL\Connection;

/**
 * Soak/chaos test helper — writes to a `test_scheduler_fire_log` table that has a
 * UNIQUE(schedule_id, slot) constraint. Any double-fire attempt will throw a DBAL
 * UniqueConstraintViolationException, which the test asserts never happens.
 *
 * Schema (applied by SoakFireLog::createTable()):
 *   CREATE TABLE IF NOT EXISTS test_scheduler_fire_log (
 *       id          SERIAL PRIMARY KEY,
 *       schedule_id VARCHAR(36) NOT NULL,
 *       slot        VARCHAR(32) NOT NULL,
 *       fired_at    TIMESTAMP NOT NULL DEFAULT NOW(),
 *       UNIQUE (schedule_id, slot)
 *   )
 */
final class SoakFireLog
{
    private const TABLE = 'test_scheduler_fire_log';

    public function __construct(private readonly Connection $connection) {}

    public function createTable(): void
    {
        $this->connection->executeStatement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id          SERIAL PRIMARY KEY,
                schedule_id VARCHAR(36)  NOT NULL,
                slot        VARCHAR(32)  NOT NULL,
                fired_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                UNIQUE (schedule_id, slot)
            )',
            self::TABLE,
        ));
    }

    public function dropTable(): void
    {
        $this->connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', self::TABLE));
    }

    /**
     * Record a fire event. Throws UniqueConstraintViolationException on double-fire.
     */
    public function record(string $scheduleId, string $slot): void
    {
        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (schedule_id, slot) VALUES (:schedule_id, :slot)',
                self::TABLE,
            ),
            ['schedule_id' => $scheduleId, 'slot' => $slot],
        );
    }

    public function countFires(string $scheduleId): int
    {
        return (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE schedule_id = :id', self::TABLE),
            ['id' => $scheduleId],
        );
    }

    public function totalFires(): int
    {
        return (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', self::TABLE));
    }

    /** @return list<array{schedule_id: string, slot: string}> */
    public function all(): array
    {
        /** @var list<array{schedule_id: string, slot: string}> */
        return $this->connection->fetchAllAssociative(
            sprintf('SELECT schedule_id, slot FROM %s ORDER BY fired_at ASC', self::TABLE),
        );
    }
}
