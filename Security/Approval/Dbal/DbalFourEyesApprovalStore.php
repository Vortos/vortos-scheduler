<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Security\Approval\Dbal;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Security\Approval\ApprovalAction;
use Vortos\Scheduler\Security\Approval\ApprovalRequest;
use Vortos\Scheduler\Security\Approval\ApprovalStatus;
use Vortos\Scheduler\Security\Approval\FourEyesApprovalStoreInterface;

final class DbalFourEyesApprovalStore implements FourEyesApprovalStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string     $table,
    ) {}

    public function save(ApprovalRequest $request): void
    {
        $row = $this->toRow($request);

        $existing = $this->connection->fetchOne(
            "SELECT id FROM {$this->table} WHERE id = ?",
            [$request->id],
        );

        if ($existing === false) {
            $this->connection->insert($this->table, $row);
        } else {
            $this->connection->update($this->table, $row, ['id' => $request->id]);
        }
    }

    public function findById(string $id): ?ApprovalRequest
    {
        $row = $this->connection->fetchAssociative(
            "SELECT * FROM {$this->table} WHERE id = ?",
            [$id],
        );

        return $row !== false ? $this->fromRow($row) : null;
    }

    public function findPending(ScheduleId $scheduleId, ApprovalAction $action): ?ApprovalRequest
    {
        $row = $this->connection->fetchAssociative(
            "SELECT * FROM {$this->table} WHERE schedule_id = ? AND action = ? AND status = ? ORDER BY requested_at DESC LIMIT 1",
            [$scheduleId->toString(), $action->value, ApprovalStatus::Pending->value],
        );

        return $row !== false ? $this->fromRow($row) : null;
    }

    public function findBySchedule(ScheduleId $scheduleId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT * FROM {$this->table} WHERE schedule_id = ? ORDER BY requested_at DESC",
            [$scheduleId->toString()],
        );

        return array_map($this->fromRow(...), $rows);
    }

    public function expireStaleBefore(DateTimeImmutable $cutoff): int
    {
        $affected = $this->connection->executeStatement(
            "UPDATE {$this->table} SET status = ? WHERE status = ? AND expires_at < ?",
            [
                ApprovalStatus::Expired->value,
                ApprovalStatus::Pending->value,
                $cutoff->format('Y-m-d H:i:s'),
            ],
        );

        return (int) $affected;
    }

    public function findAllPending(?string $tenantId = null): array
    {
        if ($tenantId !== null) {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT a.* FROM {$this->table} a "
                . "INNER JOIN vortos_scheduler_schedules s ON s.id = a.schedule_id "
                . "WHERE a.status = ? AND s.tenant_id = ? "
                . "ORDER BY a.requested_at DESC",
                [ApprovalStatus::Pending->value, $tenantId],
            );
        } else {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT * FROM {$this->table} WHERE status = ? ORDER BY requested_at DESC",
                [ApprovalStatus::Pending->value],
            );
        }

        return array_map($this->fromRow(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private function fromRow(array $row): ApprovalRequest
    {
        $utc = new DateTimeZone('UTC');

        return new ApprovalRequest(
            id:          (string) $row['id'],
            scheduleId:  ScheduleId::fromString((string) $row['schedule_id']),
            action:      ApprovalAction::from((string) $row['action']),
            status:      ApprovalStatus::from((string) $row['status']),
            requestedBy: (string) $row['requested_by'],
            requestedAt: new DateTimeImmutable((string) $row['requested_at'], $utc),
            expiresAt:   new DateTimeImmutable((string) $row['expires_at'], $utc),
            reason:      isset($row['reason']) ? (string) $row['reason'] : null,
            resolvedBy:  isset($row['resolved_by']) ? (string) $row['resolved_by'] : null,
            resolvedAt:  isset($row['resolved_at'])
                ? new DateTimeImmutable((string) $row['resolved_at'], $utc)
                : null,
        );
    }

    /** @return array<string, mixed> */
    private function toRow(ApprovalRequest $request): array
    {
        return [
            'id'           => $request->id,
            'schedule_id'  => $request->scheduleId->toString(),
            'action'       => $request->action->value,
            'status'       => $request->status->value,
            'requested_by' => $request->requestedBy,
            'requested_at' => $request->requestedAt->format('Y-m-d H:i:s'),
            'expires_at'   => $request->expiresAt->format('Y-m-d H:i:s'),
            'reason'       => $request->reason,
            'resolved_by'  => $request->resolvedBy,
            'resolved_at'  => $request->resolvedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
