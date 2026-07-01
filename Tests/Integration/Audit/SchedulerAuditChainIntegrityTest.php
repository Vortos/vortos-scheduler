<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Integration\Audit;

use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Throwable;
use Vortos\Observability\Audit\AuditHashChain;
use Vortos\Scheduler\Audit\Dbal\DbalSchedulerAuditRepository;
use Vortos\Scheduler\Audit\SchedulerAuditChainVerifier;
use Vortos\Scheduler\Audit\SchedulerAuditEntry;
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
 * Tamper-scenario tests for the scheduler audit hash chain.
 *
 * Each test: appends legitimate entries, mutates the stored row directly via SQL,
 * then verifies that the chain verifier catches the tampering.
 *
 * Run inside the backend Docker container:
 *   docker compose exec backend php vendor/bin/phpunit \
 *     packages/Vortos/src/Scheduler/Tests/Integration/Audit/SchedulerAuditChainIntegrityTest.php
 */
final class SchedulerAuditChainIntegrityTest extends TestCase
{
    private const TABLE    = 'vortos_scheduler_audit_log';
    private const HMAC_KEY = 'test-integrity-hmac-key-2026';
    private const ENV      = 'testing-integrity';

    private Connection $connection;
    private DbalSchedulerAuditRepository $repository;
    private SchedulerAuditProjector $projector;
    private SchedulerAuditChainVerifier $verifier;
    private AuditHashChain $chain;

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

    // ── Tamper scenarios ──────────────────────────────────────────────────────

    public function test_unmodified_chain_is_intact(): void
    {
        $key      = $this->populateChain(3);
        $entries  = $this->repository->findByChainKey($key);
        $result   = $this->verifier->verify($entries, self::HMAC_KEY);

        self::assertTrue($result->intact, 'Clean chain must verify as intact');
    }

    public function test_mutated_actor_id_detected_as_content_hash_mismatch(): void
    {
        $key = $this->populateChain(3);

        // Mutate actor_id on row 1 (sequence=1) directly in DB
        $this->connection->executeStatement(
            "UPDATE " . self::TABLE . " SET actor_id = 'attacker' WHERE chain_key = :key AND sequence = 1",
            ['key' => $key],
        );

        $entries = $this->repository->findByChainKey($key);
        $result  = $this->verifier->verify($entries, self::HMAC_KEY);

        self::assertFalse($result->intact, 'Mutated actor_id must break the chain');
        self::assertSame(1, $result->brokenAtSequence);
        self::assertStringContainsString('mutated', $result->reason);
    }

    public function test_mutated_data_payload_detected(): void
    {
        $key = $this->populateChain(2);

        $this->connection->executeStatement(
            "UPDATE " . self::TABLE . " SET data = :data WHERE chain_key = :key AND sequence = 0",
            ['data' => json_encode(['injected' => true]), 'key' => $key],
        );

        $result = $this->verifier->verify($this->repository->findByChainKey($key), self::HMAC_KEY);

        self::assertFalse($result->intact);
        self::assertSame(0, $result->brokenAtSequence);
    }

    public function test_forged_entry_inserted_mid_chain_detected_via_prev_hash(): void
    {
        $key = $this->populateChain(3);

        // Overwrite prev_hash of entry at sequence=2 to a fabricated value
        $this->connection->executeStatement(
            "UPDATE " . self::TABLE . " SET prev_hash = :ph WHERE chain_key = :key AND sequence = 2",
            ['ph' => str_repeat('0', 64), 'key' => $key],
        );

        $result = $this->verifier->verify($this->repository->findByChainKey($key), self::HMAC_KEY);

        self::assertFalse($result->intact);
        self::assertSame(2, $result->brokenAtSequence);
        self::assertStringContainsString('prevHash', $result->reason);
    }

    public function test_wrong_hmac_key_breaks_signature_verification(): void
    {
        $key     = $this->populateChain(2);
        $entries = $this->repository->findByChainKey($key);

        // Verify with a DIFFERENT key → signatures invalid
        $result = $this->verifier->verify($entries, 'wrong-key');

        self::assertFalse($result->intact);
        self::assertSame(0, $result->brokenAtSequence);
        self::assertStringContainsString('HMAC', $result->reason);
    }

    public function test_deleted_middle_entry_detected_as_sequence_gap(): void
    {
        $key = $this->populateChain(4);

        // Delete the entry at sequence=1 to create a gap
        $this->connection->executeStatement(
            "DELETE FROM " . self::TABLE . " WHERE chain_key = :key AND sequence = 1",
            ['key' => $key],
        );

        $entries = $this->repository->findByChainKey($key);
        // Now entries are: 0, 2, 3 (gap at 1)
        $result = $this->verifier->verify($entries, self::HMAC_KEY);

        self::assertFalse($result->intact);
        self::assertStringContainsString('sequence', (string) $result->reason);
    }

    public function test_content_hash_overwrite_detected(): void
    {
        $key = $this->populateChain(2);

        $this->connection->executeStatement(
            "UPDATE " . self::TABLE . " SET content_hash = :ch WHERE chain_key = :key AND sequence = 0",
            ['ch' => str_repeat('f', 64), 'key' => $key],
        );

        $result = $this->verifier->verify($this->repository->findByChainKey($key), self::HMAC_KEY);

        self::assertFalse($result->intact);
    }

    public function test_signature_overwrite_detected(): void
    {
        $key = $this->populateChain(2);

        $this->connection->executeStatement(
            "UPDATE " . self::TABLE . " SET signature = :sig WHERE chain_key = :key AND sequence = 0",
            ['sig' => str_repeat('a', 64), 'key' => $key],
        );

        $result = $this->verifier->verify($this->repository->findByChainKey($key), self::HMAC_KEY);

        self::assertFalse($result->intact);
        self::assertSame(0, $result->brokenAtSequence);
        self::assertStringContainsString('HMAC', (string) $result->reason);
    }

    public function test_empty_chain_verifies_intact(): void
    {
        $result = $this->verifier->verify([], self::HMAC_KEY);

        self::assertTrue($result->intact, 'An empty chain has nothing to be broken');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function populateChain(int $count): string
    {
        $suffix   = uniqid();
        $tenantId = 'intg-tamper-' . $suffix;
        $schedule = $this->makeSchedule($tenantId);

        $this->projector->onScheduleCreated($schedule, 'actor');

        for ($i = 1; $i < $count; $i++) {
            $this->projector->onScheduleUpdated($schedule, 'actor', "Change #{$i}");
        }

        return 'scheduler:' . $tenantId . ':' . self::ENV;
    }

    private function makeSchedule(string $tenantId): Schedule
    {
        return new Schedule(
            id:       ScheduleId::generate(),
            name:     'tamper-test-schedule',
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

        $this->connection->executeStatement(
            "CREATE INDEX IF NOT EXISTS idx_{$t}_chain_seq ON {$t} (chain_key, sequence)"
        );
    }

    private function cleanTestRows(): void
    {
        try {
            $this->connection->executeStatement(
                "DELETE FROM " . self::TABLE . " WHERE chain_key LIKE 'scheduler:%:testing-integrity'"
            );
        } catch (Throwable) {
        }
    }
}
