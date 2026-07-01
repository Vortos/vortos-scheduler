<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Integration\Audit;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Throwable;
use Vortos\Observability\Audit\AuditHashChain;
use Vortos\Scheduler\Audit\Dbal\DbalSchedulerAuditRepository;
use Vortos\Scheduler\Audit\SchedulerAuditChainVerifier;
use Vortos\Scheduler\Audit\SchedulerAuditEntry;
use Vortos\Scheduler\Audit\SchedulerAuditEvent;
use Vortos\Scheduler\Audit\SchedulerAuditProjector;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;
use Vortos\Scheduler\Tests\Unit\Security\Support\StubAllowlistedCommand;

/**
 * Integration tests for DbalSchedulerAuditRepository against live PostgreSQL.
 *
 * Run inside the backend Docker container:
 *   docker compose exec backend php vendor/bin/phpunit \
 *     packages/Vortos/src/Scheduler/Tests/Integration/Audit/DbalSchedulerAuditRepositoryTest.php
 */
final class DbalSchedulerAuditRepositoryTest extends TestCase
{
    private const TABLE    = 'vortos_scheduler_audit_log';
    private const HMAC_KEY = 'test-integration-hmac-key-2026';
    private const ENV      = 'testing';

    private Connection $connection;
    private DbalSchedulerAuditRepository $repository;
    private SchedulerAuditProjector $projector;
    private AuditHashChain $chain;
    private SchedulerAuditChainVerifier $verifier;

    protected function setUp(): void
    {
        $this->connection = $this->connectOrSkip();
        $this->ensureTable();
        $this->cleanTestRows();

        $this->chain      = new AuditHashChain();
        $this->repository = new DbalSchedulerAuditRepository($this->connection, self::TABLE);
        $this->projector  = new SchedulerAuditProjector($this->repository, self::HMAC_KEY, self::ENV, $this->chain);
        $this->verifier   = new SchedulerAuditChainVerifier($this->chain);
    }

    protected function tearDown(): void
    {
        $this->cleanTestRows();
    }

    // ── Basic append + read back ──────────────────────────────────────────────

    public function test_append_and_read_back_single_entry(): void
    {
        $schedule = $this->makeSchedule();
        $this->projector->onScheduleCreated($schedule, 'user-1');

        $entries = $this->repository->findByChainKey($this->chainKey($schedule->tenantId), 10);

        self::assertCount(1, $entries);
        self::assertSame(SchedulerAuditEvent::ScheduleCreated->value, $entries[0]->eventType);
        self::assertSame('user-1', $entries[0]->actorId);
        self::assertSame($schedule->id->toString(), $entries[0]->scheduleId);
    }

    public function test_sequential_appends_form_contiguous_sequence(): void
    {
        $schedule = $this->makeSchedule();
        $this->projector->onScheduleCreated($schedule, 'user-1');
        $this->projector->onScheduleUpdated($schedule, 'user-1', 'Fixed interval');
        $this->projector->onSchedulePaused($schedule, 'admin', 'Maintenance');

        $entries = $this->repository->findByChainKey($this->chainKey($schedule->tenantId));

        self::assertCount(3, $entries);
        self::assertSame(0, $entries[0]->sequence);
        self::assertSame(1, $entries[1]->sequence);
        self::assertSame(2, $entries[2]->sequence);
    }

    public function test_chain_integrity_verified_after_appends(): void
    {
        $schedule = $this->makeSchedule();
        $this->projector->onScheduleCreated($schedule, 'user-1');
        $this->projector->onScheduleUpdated($schedule, 'user-1', 'Adjusted');

        $key     = $this->chainKey($schedule->tenantId);
        $entries = $this->repository->findByChainKey($key);

        $result = $this->verifier->verify($entries, self::HMAC_KEY);

        self::assertTrue($result->intact, 'Chain must be intact after sequential appends');
    }

    public function test_first_entry_uses_genesis_hash_as_prev_hash(): void
    {
        $schedule = $this->makeSchedule();
        $this->projector->onScheduleCreated($schedule, 'user-1');

        $entries = $this->repository->findByChainKey($this->chainKey($schedule->tenantId));

        self::assertSame(AuditHashChain::GENESIS_HASH, $entries[0]->prevHash);
    }

    // ── findBySchedule ────────────────────────────────────────────────────────

    public function test_find_by_schedule_returns_entries_for_that_schedule(): void
    {
        $scheduleA = $this->makeSchedule(id: ScheduleId::generate());
        $scheduleB = $this->makeSchedule(id: ScheduleId::generate());

        $this->projector->onScheduleCreated($scheduleA, 'actor');
        $this->projector->onScheduleCreated($scheduleB, 'actor');

        $entries = $this->repository->findBySchedule($scheduleA->id->toString(), $scheduleA->tenantId);

        self::assertCount(1, $entries);
        self::assertSame($scheduleA->id->toString(), $entries[0]->scheduleId);
    }

    // ── findByTenant ──────────────────────────────────────────────────────────

    public function test_find_by_tenant_returns_only_that_tenants_entries(): void
    {
        $scheduleTa = $this->makeSchedule(tenantId: 'ta-' . uniqid());
        $scheduleTb = $this->makeSchedule(tenantId: 'tb-' . uniqid());

        $this->projector->onScheduleCreated($scheduleTa, 'actor');
        $this->projector->onScheduleCreated($scheduleTb, 'actor');

        $entriesTa = $this->repository->findByTenant($scheduleTa->tenantId);

        self::assertCount(1, $entriesTa);
        self::assertSame($scheduleTa->tenantId, $entriesTa[0]->tenantId);
    }

    // ── stream ────────────────────────────────────────────────────────────────

    public function test_stream_yields_entries_in_order(): void
    {
        $schedule = $this->makeSchedule();
        $this->projector->onScheduleCreated($schedule, 'actor');
        $this->projector->onSchedulePaused($schedule, 'admin', 'Test');

        $key     = $this->chainKey($schedule->tenantId);
        $entries = iterator_to_array($this->repository->stream($key));

        self::assertCount(2, $entries);
        self::assertSame(0, $entries[0]->sequence);
        self::assertSame(1, $entries[1]->sequence);
    }

    // ── Tenant isolation ─────────────────────────────────────────────────────

    public function test_different_tenants_use_separate_chains(): void
    {
        $schTa = $this->makeSchedule(tenantId: 'isolated-ta');
        $schTb = $this->makeSchedule(tenantId: 'isolated-tb');

        $this->projector->onScheduleCreated($schTa, 'actor');
        $this->projector->onScheduleCreated($schTb, 'actor');
        $this->projector->onScheduleUpdated($schTb, 'actor', 'Change');

        $keyTa = $this->chainKey('isolated-ta');
        $keyTb = $this->chainKey('isolated-tb');

        $entriesTa = $this->repository->findByChainKey($keyTa);
        $entriesTb = $this->repository->findByChainKey($keyTb);

        // Tenant A has 1 entry; Tenant B has 2
        self::assertCount(1, $entriesTa);
        self::assertCount(2, $entriesTb);

        // Each chain starts at sequence 0 independently
        self::assertSame(0, $entriesTa[0]->sequence);
        self::assertSame(0, $entriesTb[0]->sequence);

        // Both chains are independently intact
        self::assertTrue($this->verifier->verify($entriesTa, self::HMAC_KEY)->intact);
        self::assertTrue($this->verifier->verify($entriesTb, self::HMAC_KEY)->intact);
    }

    // ── Data payload safety ───────────────────────────────────────────────────

    public function test_hmac_key_never_appears_in_stored_payload(): void
    {
        $schedule = $this->makeSchedule();
        $this->projector->onScheduleCreated($schedule, 'user-1');

        $entries = $this->repository->findByChainKey($this->chainKey($schedule->tenantId));

        $raw = json_encode($entries[0]->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::HMAC_KEY, $raw);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function chainKey(?string $tenantId): string
    {
        return 'scheduler:' . ($tenantId ?? 'system') . ':' . self::ENV;
    }

    private function makeSchedule(
        ?ScheduleId $id = null,
        ?string $tenantId = 'integ-tenant-1',
    ): Schedule {
        return new Schedule(
            id:       $id ?? ScheduleId::generate(),
            name:     'integration-test-schedule',
            source:   ScheduleSource::Dynamic,
            trigger:  new IntervalTrigger(3600),
            command:  new CommandSpec(StubAllowlistedCommand::class, []),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: $tenantId,
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
        } catch (Throwable $e) {
            $this->markTestSkipped('Postgres not reachable: ' . $e->getMessage());
        }
    }

    private function ensureTable(): void
    {
        $t = self::TABLE;

        $this->connection->executeStatement("
            CREATE TABLE IF NOT EXISTS {$t} (
                entry_id     VARCHAR(36)  NOT NULL,
                sequence     INT          NOT NULL,
                event_type   VARCHAR(64)  NOT NULL,
                actor_id     VARCHAR(255) NOT NULL,
                tenant_id    VARCHAR(255) NULL,
                schedule_id  VARCHAR(255) NULL,
                slot         TEXT         NULL,
                shard_index  INT          NULL,
                occurred_at  VARCHAR(32)  NOT NULL,
                data         TEXT         NOT NULL,
                chain_key    VARCHAR(255) NOT NULL,
                prev_hash    CHAR(64)     NOT NULL,
                content_hash CHAR(64)     NOT NULL,
                signature    CHAR(64)     NOT NULL,
                CONSTRAINT pk_{$t} PRIMARY KEY (entry_id),
                CONSTRAINT uq_{$t}_seq UNIQUE (chain_key, sequence)
            )
        ");

        $this->connection->executeStatement("
            CREATE INDEX IF NOT EXISTS idx_{$t}_chain_seq ON {$t} (chain_key, sequence)
        ");
        $this->connection->executeStatement("
            CREATE INDEX IF NOT EXISTS idx_{$t}_tenant_at ON {$t} (tenant_id, occurred_at)
        ");
        $this->connection->executeStatement("
            CREATE INDEX IF NOT EXISTS idx_{$t}_sched_at ON {$t} (schedule_id, occurred_at)
        ");
    }

    private function cleanTestRows(): void
    {
        try {
            $this->connection->executeStatement(
                "DELETE FROM " . self::TABLE . " WHERE chain_key LIKE 'scheduler:%:testing'
                  OR chain_key LIKE 'scheduler:isolated-%:testing'
                  OR chain_key LIKE 'scheduler:ta-%:testing'
                  OR chain_key LIKE 'scheduler:tb-%:testing'"
            );
        } catch (Throwable) {
        }
    }
}
