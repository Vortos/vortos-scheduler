<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Store\Dbal;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Schedule\Policy\Jitter;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Store\Exception\OptimisticLockException;
use Vortos\Scheduler\Store\Exception\ScheduleNameConflictException;
use Vortos\Scheduler\Store\Exception\ScheduleNotFoundException;
use Vortos\Scheduler\Store\ScheduleStoreInterface;

/**
 * DBAL driver for ScheduleStoreInterface. PostgreSQL-targeted, DBAL-portable.
 *
 * Uses the write connection only — never a read replica. Applications that inject
 * a separate read alias MUST ensure this class receives the write connection so that
 * read-after-write consistency is guaranteed within the same request/worker.
 *
 * Optimistic concurrency:
 *   version = 0 → INSERT with version = 1
 *   version > 0 → UPDATE WHERE id = ? AND version = ? (version incremented in SQL)
 *
 * Tenant isolation:
 *   All single-tenant methods include tenant_id in the WHERE clause.
 *   findAllActive() has no tenant filter (daemon-level only).
 */
final class DbalScheduleStore implements ScheduleStoreInterface
{
    public function __construct(
        private readonly Connection         $connection,
        private readonly ScheduleSerializer $serializer,
        private readonly string             $table = 'vortos_scheduler_schedules',
    ) {}

    public function save(Schedule $schedule): void
    {
        if ($schedule->version === 0) {
            $this->insert($schedule);
        } else {
            $this->update($schedule);
        }
    }

    public function find(ScheduleId $id, ?string $tenantId): ?Schedule
    {
        $row = $this->fetchByIdAndTenant((string) $id, $tenantId);

        return $row !== false ? $this->fromRow($row) : null;
    }

    public function findByName(string $name, ?string $tenantId): ?Schedule
    {
        $row = $tenantId !== null
            ? $this->connection->fetchAssociative(
                "SELECT * FROM {$this->table} WHERE name = ? AND tenant_id = ?",
                [$name, $tenantId],
            )
            : $this->connection->fetchAssociative(
                "SELECT * FROM {$this->table} WHERE name = ? AND tenant_id IS NULL",
                [$name],
            );

        return $row !== false ? $this->fromRow($row) : null;
    }

    public function delete(ScheduleId $id, ?string $tenantId): void
    {
        $affected = $tenantId !== null
            ? $this->connection->executeStatement(
                "DELETE FROM {$this->table} WHERE id = ? AND tenant_id = ?",
                [(string) $id, $tenantId],
            )
            : $this->connection->executeStatement(
                "DELETE FROM {$this->table} WHERE id = ? AND tenant_id IS NULL",
                [(string) $id],
            );

        if ($affected === 0) {
            throw new ScheduleNotFoundException((string) $id, $tenantId);
        }
    }

    public function findActive(?string $tenantId): iterable
    {
        $rows = $tenantId !== null
            ? $this->connection->fetchAllAssociative(
                "SELECT * FROM {$this->table} WHERE status = ? AND tenant_id = ? ORDER BY id",
                [ScheduleStatus::Active->value, $tenantId],
            )
            : $this->connection->fetchAllAssociative(
                "SELECT * FROM {$this->table} WHERE status = ? AND tenant_id IS NULL ORDER BY id",
                [ScheduleStatus::Active->value],
            );

        return array_map($this->fromRow(...), $rows);
    }

    public function findAllActive(): iterable
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT * FROM {$this->table} WHERE status = ? ORDER BY tenant_id NULLS FIRST, id",
            [ScheduleStatus::Active->value],
        );

        return array_map($this->fromRow(...), $rows);
    }

    public function findAll(?string $tenantId): iterable
    {
        $rows = $tenantId !== null
            ? $this->connection->fetchAllAssociative(
                "SELECT * FROM {$this->table} WHERE tenant_id = ? ORDER BY name",
                [$tenantId],
            )
            : $this->connection->fetchAllAssociative(
                "SELECT * FROM {$this->table} WHERE tenant_id IS NULL ORDER BY name",
            );

        return array_map($this->fromRow(...), $rows);
    }

    // ─────────────────────────────────────────────────────────────
    // Private: INSERT
    // ─────────────────────────────────────────────────────────────

    private function insert(Schedule $schedule): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $ts  = $now->format('Y-m-d H:i:s');

        [$triggerType, $triggerData] = $this->serializer->serializeTrigger($schedule->trigger);

        $row = [
            'id'             => (string) $schedule->id,
            'name'           => $schedule->name,
            'tenant_id'      => $schedule->tenantId,
            'source'         => $schedule->source->value,
            'status'         => $schedule->status->value,
            'trigger_type'   => $triggerType,
            'trigger_data'   => $triggerData,
            'command_class'  => $schedule->command->commandClass,
            'command_payload'=> json_encode($schedule->command->payload, JSON_THROW_ON_ERROR),
            'misfire_policy' => $this->serializer->serializeMisfirePolicy($schedule->misfire),
            'overlap_policy' => $schedule->overlap->value,
            'timezone'       => $schedule->timezone->getName(),
            'jitter_seconds' => $schedule->jitter?->windowSeconds,
            'sensitive'      => $schedule->sensitive ? 1 : 0,
            'metadata'       => json_encode($schedule->metadata, JSON_THROW_ON_ERROR),
            'version'        => 1,
            'created_at'     => $ts,
            'updated_at'     => $ts,
        ];

        try {
            $this->connection->insert($this->table, $row);
        } catch (UniqueConstraintViolationException $e) {
            throw new ScheduleNameConflictException($schedule->name, $schedule->tenantId, $e);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Private: UPDATE (optimistic CAS)
    // ─────────────────────────────────────────────────────────────

    private function update(Schedule $schedule): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        [$triggerType, $triggerData] = $this->serializer->serializeTrigger($schedule->trigger);

        $affected = $this->connection->executeStatement(
            "UPDATE {$this->table}
             SET    name            = ?,
                    status          = ?,
                    trigger_type    = ?,
                    trigger_data    = ?,
                    command_class   = ?,
                    command_payload = ?,
                    misfire_policy  = ?,
                    overlap_policy  = ?,
                    timezone        = ?,
                    jitter_seconds  = ?,
                    sensitive       = ?,
                    metadata        = ?,
                    version         = version + 1,
                    updated_at      = ?
             WHERE  id      = ?
               AND  version = ?",
            [
                $schedule->name,
                $schedule->status->value,
                $triggerType,
                $triggerData,
                $schedule->command->commandClass,
                json_encode($schedule->command->payload, JSON_THROW_ON_ERROR),
                $this->serializer->serializeMisfirePolicy($schedule->misfire),
                $schedule->overlap->value,
                $schedule->timezone->getName(),
                $schedule->jitter?->windowSeconds,
                $schedule->sensitive ? 1 : 0,
                json_encode($schedule->metadata, JSON_THROW_ON_ERROR),
                $now->format('Y-m-d H:i:s'),
                (string) $schedule->id,
                $schedule->version,
            ],
        );

        if ($affected === 0) {
            $exists = $this->connection->fetchOne(
                "SELECT id FROM {$this->table} WHERE id = ?",
                [(string) $schedule->id],
            );

            if ($exists === false) {
                throw new ScheduleNotFoundException((string) $schedule->id, $schedule->tenantId);
            }

            throw new OptimisticLockException((string) $schedule->id, $schedule->version);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Private: fetch helpers
    // ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed>|false */
    private function fetchByIdAndTenant(string $id, ?string $tenantId): array|false
    {
        return $tenantId !== null
            ? $this->connection->fetchAssociative(
                "SELECT * FROM {$this->table} WHERE id = ? AND tenant_id = ?",
                [$id, $tenantId],
            )
            : $this->connection->fetchAssociative(
                "SELECT * FROM {$this->table} WHERE id = ? AND tenant_id IS NULL",
                [$id],
            );
    }

    // ─────────────────────────────────────────────────────────────
    // Private: hydration
    // ─────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $row */
    private function fromRow(array $row): Schedule
    {
        $timezone = new DateTimeZone((string) $row['timezone']);
        $trigger  = $this->serializer->deserializeTrigger(
            (string) $row['trigger_type'],
            (string) $row['trigger_data'],
            $timezone,
        );

        /** @var array<mixed> $payload */
        $payload = json_decode((string) $row['command_payload'], true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, string> $metadata */
        $metadata = json_decode((string) $row['metadata'], true, 512, JSON_THROW_ON_ERROR);

        return new Schedule(
            id:        ScheduleId::fromString((string) $row['id']),
            name:      (string) $row['name'],
            source:    ScheduleSource::from((string) $row['source']),
            trigger:   $trigger,
            command:   new CommandSpec((string) $row['command_class'], $payload),
            misfire:   $this->serializer->deserializeMisfirePolicy((string) $row['misfire_policy']),
            overlap:   OverlapPolicy::from((string) $row['overlap_policy']),
            timezone:  $timezone,
            jitter:    $row['jitter_seconds'] !== null ? new Jitter((int) $row['jitter_seconds']) : null,
            status:    ScheduleStatus::from((string) $row['status']),
            tenantId:  $row['tenant_id'] !== null ? (string) $row['tenant_id'] : null,
            sensitive: (bool) $row['sensitive'],
            metadata:  $metadata,
            version:   (int) $row['version'],
        );
    }
}
