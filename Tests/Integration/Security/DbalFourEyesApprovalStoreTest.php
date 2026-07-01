<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Integration\Security;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Security\Approval\ApprovalAction;
use Vortos\Scheduler\Security\Approval\ApprovalRequest;
use Vortos\Scheduler\Security\Approval\ApprovalStatus;
use Vortos\Scheduler\Security\Approval\Dbal\DbalFourEyesApprovalStore;

/**
 * Integration tests for DbalFourEyesApprovalStore against a real PostgreSQL database.
 *
 * Skipped automatically when the database is unreachable (CI / local dev without DB).
 */
final class DbalFourEyesApprovalStoreTest extends TestCase
{
    private const TABLE = 'vortos_scheduler_approvals';

    private Connection $connection;
    private DbalFourEyesApprovalStore $store;
    private ScheduleId $scheduleId;

    protected function setUp(): void
    {
        $this->connection = $this->connectOrSkip();
        $this->store      = new DbalFourEyesApprovalStore($this->connection, self::TABLE);
        $this->scheduleId = ScheduleId::generate();

        $this->ensureTable();
        $this->cleanTestRows();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->cleanTestRows();
        }
    }

    // ── save + findById ───────────────────────────────────────────────────────

    public function test_save_and_find_by_id_round_trip(): void
    {
        $request = $this->makeRequest('uuid-1');
        $this->store->save($request);

        $found = $this->store->findById('uuid-1');

        self::assertNotNull($found);
        self::assertSame('uuid-1', $found->id);
        self::assertSame($this->scheduleId->toString(), $found->scheduleId->toString());
        self::assertSame(ApprovalAction::Activate, $found->action);
        self::assertSame(ApprovalStatus::Pending, $found->status);
        self::assertSame('requester-1', $found->requestedBy);
        self::assertSame('quarterly run', $found->reason);
    }

    public function test_find_by_id_returns_null_when_not_found(): void
    {
        self::assertNull($this->store->findById('non-existent-uuid'));
    }

    // ── save is idempotent (upsert) ───────────────────────────────────────────

    public function test_save_updates_existing_record(): void
    {
        $request = $this->makeRequest('uuid-2');
        $this->store->save($request);

        $updated = $request->withResolution(
            ApprovalStatus::Approved,
            'approver-1',
            new DateTimeImmutable('2026-01-15 12:00:00'),
        );
        $this->store->save($updated);

        $found = $this->store->findById('uuid-2');
        self::assertNotNull($found);
        self::assertSame(ApprovalStatus::Approved, $found->status);
        self::assertSame('approver-1', $found->resolvedBy);
    }

    // ── findPending ───────────────────────────────────────────────────────────

    public function test_find_pending_returns_pending_request(): void
    {
        $request = $this->makeRequest('uuid-3');
        $this->store->save($request);

        $found = $this->store->findPending($this->scheduleId, ApprovalAction::Activate);

        self::assertNotNull($found);
        self::assertSame('uuid-3', $found->id);
    }

    public function test_find_pending_returns_null_when_approved(): void
    {
        $request = $this->makeRequest('uuid-4');
        $this->store->save($request);

        $approved = $request->withResolution(
            ApprovalStatus::Approved,
            'approver-1',
            new DateTimeImmutable('2026-01-15 12:00:00'),
        );
        $this->store->save($approved);

        $found = $this->store->findPending($this->scheduleId, ApprovalAction::Activate);
        self::assertNull($found);
    }

    public function test_find_pending_is_action_specific(): void
    {
        $activateRequest = $this->makeRequest('uuid-5-activate', ApprovalAction::Activate);
        $this->store->save($activateRequest);

        $found = $this->store->findPending($this->scheduleId, ApprovalAction::RunNow);
        self::assertNull($found, 'RunNow request should not be found when searching for Activate');
    }

    public function test_find_pending_is_schedule_specific(): void
    {
        $otherId   = ScheduleId::generate();
        $request   = $this->makeRequest('uuid-6', ApprovalAction::Activate, $otherId);
        $this->store->save($request);

        $found = $this->store->findPending($this->scheduleId, ApprovalAction::Activate);
        self::assertNull($found, 'Should not find request for different schedule');
    }

    // ── findBySchedule ────────────────────────────────────────────────────────

    public function test_find_by_schedule_returns_all_requests(): void
    {
        $this->store->save($this->makeRequest('uuid-7-a', ApprovalAction::Activate));
        $this->store->save($this->makeRequest('uuid-7-b', ApprovalAction::RunNow));

        $results = $this->store->findBySchedule($this->scheduleId);

        self::assertCount(2, $results);
    }

    public function test_find_by_schedule_excludes_other_schedules(): void
    {
        $otherId = ScheduleId::generate();
        $this->store->save($this->makeRequest('uuid-8', ApprovalAction::Activate, $otherId));
        $this->store->save($this->makeRequest('uuid-8b', ApprovalAction::Activate, $this->scheduleId));

        $results = $this->store->findBySchedule($this->scheduleId);
        self::assertCount(1, $results);
        self::assertSame('uuid-8b', $results[0]->id);
    }

    // ── expireStaleBefore ─────────────────────────────────────────────────────

    public function test_expire_stale_before_transitions_expired_pending_requests(): void
    {
        $past   = new DateTimeImmutable('2026-01-10 00:00:00');
        $future = new DateTimeImmutable('2026-02-01 00:00:00');

        $stale  = $this->makeRequestWithExpiry('uuid-9-stale', $past);
        $active = $this->makeRequestWithExpiry('uuid-9-active', $future);

        $this->store->save($stale);
        $this->store->save($active);

        $count = $this->store->expireStaleBefore(new DateTimeImmutable('2026-01-15 00:00:00'));

        self::assertSame(1, $count);

        $staleFound = $this->store->findById('uuid-9-stale');
        self::assertSame(ApprovalStatus::Expired, $staleFound?->status);

        $activeFound = $this->store->findById('uuid-9-active');
        self::assertSame(ApprovalStatus::Pending, $activeFound?->status);
    }

    public function test_expire_stale_returns_zero_when_none_expired(): void
    {
        $count = $this->store->expireStaleBefore(new DateTimeImmutable('2025-01-01 00:00:00'));
        self::assertSame(0, $count);
    }

    // ── null / optional fields ────────────────────────────────────────────────

    public function test_round_trip_preserves_null_reason(): void
    {
        $request = new ApprovalRequest(
            id:          'uuid-10',
            scheduleId:  $this->scheduleId,
            action:      ApprovalAction::Activate,
            status:      ApprovalStatus::Pending,
            requestedBy: 'req-1',
            requestedAt: new DateTimeImmutable('2026-01-15 10:00:00'),
            expiresAt:   new DateTimeImmutable('2026-01-16 10:00:00'),
            reason:      null,
            resolvedBy:  null,
            resolvedAt:  null,
        );

        $this->store->save($request);
        $found = $this->store->findById('uuid-10');

        self::assertNull($found?->reason);
        self::assertNull($found?->resolvedBy);
        self::assertNull($found?->resolvedAt);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeRequest(
        string         $id,
        ApprovalAction $action = ApprovalAction::Activate,
        ?ScheduleId    $scheduleId = null,
    ): ApprovalRequest {
        return new ApprovalRequest(
            id:          $id,
            scheduleId:  $scheduleId ?? $this->scheduleId,
            action:      $action,
            status:      ApprovalStatus::Pending,
            requestedBy: 'requester-1',
            requestedAt: new DateTimeImmutable('2026-01-15 09:00:00'),
            expiresAt:   new DateTimeImmutable('2026-01-16 09:00:00'),
            reason:      'quarterly run',
            resolvedBy:  null,
            resolvedAt:  null,
        );
    }

    private function makeRequestWithExpiry(string $id, DateTimeImmutable $expiresAt): ApprovalRequest
    {
        return new ApprovalRequest(
            id:          $id,
            scheduleId:  $this->scheduleId,
            action:      ApprovalAction::Activate,
            status:      ApprovalStatus::Pending,
            requestedBy: 'req-1',
            requestedAt: new DateTimeImmutable('2026-01-14 00:00:00'),
            expiresAt:   $expiresAt,
            reason:      null,
            resolvedBy:  null,
            resolvedAt:  null,
        );
    }

    private function connectOrSkip(): Connection
    {
        try {
            $conn = DriverManager::getConnection([
                'driver'   => 'pdo_pgsql',
                'host'     => $_ENV['VORTOS_WRITE_DB_HOST'] ?? 'write_db',
                'port'     => (int) ($_ENV['VORTOS_WRITE_DB_PORT'] ?? 5432),
                'user'     => $_ENV['VORTOS_WRITE_DB_USER'] ?? 'postgres',
                'password' => $_ENV['VORTOS_WRITE_DB_PASSWORD'] ?? '12345',
                'dbname'   => $_ENV['VORTOS_WRITE_DB_NAME'] ?? 'squaura',
            ]);
            $conn->executeQuery('SELECT 1');

            return $conn;
        } catch (\Throwable $e) {
            $this->markTestSkipped('PostgreSQL not reachable: ' . $e->getMessage());
        }
    }

    private function ensureTable(): void
    {
        $t = self::TABLE;
        $this->connection->executeStatement("
            CREATE TABLE IF NOT EXISTS {$t} (
                id           VARCHAR(36)  NOT NULL,
                schedule_id  VARCHAR(36)  NOT NULL,
                action       VARCHAR(20)  NOT NULL,
                status       VARCHAR(20)  NOT NULL DEFAULT 'pending',
                requested_by VARCHAR(255) NOT NULL,
                requested_at TIMESTAMPTZ  NOT NULL,
                expires_at   TIMESTAMPTZ  NOT NULL,
                reason       TEXT         NULL,
                resolved_by  VARCHAR(255) NULL,
                resolved_at  TIMESTAMPTZ  NULL,
                CONSTRAINT pk_{$t} PRIMARY KEY (id)
            )
        ");
    }

    private function cleanTestRows(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM ' . self::TABLE . ' WHERE schedule_id = ?',
            [$this->scheduleId->toString()],
        );
        // Also clean up fixture rows with literal IDs
        foreach (['uuid-9-stale', 'uuid-9-active', 'uuid-8', 'uuid-8b', 'uuid-10'] as $id) {
            $this->connection->executeStatement(
                'DELETE FROM ' . self::TABLE . ' WHERE id = ?',
                [$id],
            );
        }
    }
}
