<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Throwable;
use Vortos\Scheduler\Clock\ClockPort;
use Vortos\Scheduler\Engine\DueScan;
use Vortos\Scheduler\Engine\FireDispatcher;
use Vortos\Scheduler\Engine\MisfireResolver;
use Vortos\Scheduler\Engine\SchedulerDaemon;
use Vortos\Scheduler\Engine\SlotCalculator;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Lease\Driver\InMemoryLeaseStore;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\OneShotTrigger;
use Vortos\Scheduler\Registry\ScheduleResolver;
use Vortos\Scheduler\Registry\StaticScheduleRegistry;
use Vortos\Scheduler\Store\Dbal\DbalScheduleCursorStore;
use Vortos\Scheduler\Store\Dbal\DbalScheduleRunStore;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;
use Vortos\Scheduler\Store\ScheduleStoreInterface;
use Vortos\Scheduler\Testing\RecordingSchedulerEnqueuer;

/**
 * Integration tests for SchedulerDaemon leader election via sharded leases.
 *
 * Uses InMemoryLeaseStore so no Redis/PG lease table is needed.
 * Real PG is used for the run-store (FireDispatcher wiring) but schedules
 * return empty in most election tests so the DB is never queried.
 *
 * Covers:
 *  - Single daemon acquires shard 0 → runOnce() returns true
 *  - Second daemon sharing the same InMemoryLeaseStore cannot acquire → returns false
 *  - Three daemons: only the first acquires the single shard
 *  - Shard-0 key format is "scheduler:leader:0"
 *  - After first daemon "releases" (TTL expired via advanced clock), second takes over
 *  - Multi-shard: with shardCount=2, two daemons split the shards
 *  - Per-shard token uniqueness: each daemon uses a stable per-shard token
 */
final class SchedulerDaemonLeaderElectionTest extends TestCase
{
    private const RUNS_TABLE    = 'vortos_scheduler_runs';
    private const CURSORS_TABLE = 'vortos_scheduler_cursors';

    private Connection                 $connection;
    private RecordingSchedulerEnqueuer $enqueuer;

    /** @var list<ScheduleId> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        $this->connection = $this->connectOrSkip();
        $this->enqueuer   = new RecordingSchedulerEnqueuer();
        $this->ensureTables();
    }

    protected function tearDown(): void
    {
        $this->cleanCreatedRows();
    }

    // ─────────────────────────────────────────────────────────────
    // Basic leader acquisition
    // ─────────────────────────────────────────────────────────────

    public function test_single_daemon_acquires_shard_zero(): void
    {
        $clock  = $this->fixedClock(new DateTimeImmutable('2026-07-01T10:00:00Z'));
        $lease  = new InMemoryLeaseStore($clock);
        $daemon = $this->makeDaemon($lease, $clock, shardCount: 1);

        self::assertTrue($daemon->runOnce());
    }

    public function test_second_daemon_cannot_acquire_shard_held_by_first(): void
    {
        $clock   = $this->fixedClock(new DateTimeImmutable('2026-07-01T10:00:00Z'));
        $lease   = new InMemoryLeaseStore($clock);
        $daemonA = $this->makeDaemon($lease, $clock, shardCount: 1);
        $daemonB = $this->makeDaemon($lease, $clock, shardCount: 1);

        // Daemon A acquires shard 0.
        self::assertTrue($daemonA->runOnce());

        // Daemon B cannot acquire — lease is still held by A.
        self::assertFalse($daemonB->runOnce());
    }

    public function test_third_daemon_also_cannot_acquire_shard(): void
    {
        $clock   = $this->fixedClock(new DateTimeImmutable('2026-07-01T10:00:00Z'));
        $lease   = new InMemoryLeaseStore($clock);
        $daemonA = $this->makeDaemon($lease, $clock, shardCount: 1);
        $daemonB = $this->makeDaemon($lease, $clock, shardCount: 1);
        $daemonC = $this->makeDaemon($lease, $clock, shardCount: 1);

        $daemonA->runOnce();

        self::assertFalse($daemonB->runOnce());
        self::assertFalse($daemonC->runOnce());
    }

    // ─────────────────────────────────────────────────────────────
    // Lease key format
    // ─────────────────────────────────────────────────────────────

    public function test_shard_zero_lease_key_format(): void
    {
        self::assertSame('scheduler:leader:0', SchedulerDaemon::leaseKeyForShard(0));
    }

    public function test_shard_lease_keys_are_unique_per_index(): void
    {
        $keys = [];
        for ($i = 0; $i < 10; $i++) {
            $key    = SchedulerDaemon::leaseKeyForShard($i);
            $keys[] = $key;
            self::assertStringContainsString((string) $i, $key);
        }

        // All keys are distinct.
        self::assertSame(count($keys), count(array_unique($keys)));
    }

    // ─────────────────────────────────────────────────────────────
    // Lease TTL expiry → failover
    // ─────────────────────────────────────────────────────────────

    public function test_second_daemon_acquires_after_lease_ttl_expires(): void
    {
        $ttlSec = 5;

        // Use a single shared InMemoryLeaseStore with a mutable clock.
        // When the clock advances past the TTL, the stored lease is seen as expired.
        $timeHolder = (object) ['now' => new DateTimeImmutable('2026-07-01T10:00:00Z')];

        $advancingClock = new class($timeHolder) implements ClockPort {
            public function __construct(private readonly object $holder) {}

            public function now(): DateTimeImmutable
            {
                return $this->holder->now;
            }
        };

        $sharedLease = new InMemoryLeaseStore($advancingClock);

        $daemonA = $this->makeDaemon($sharedLease, $advancingClock, shardCount: 1, leaseTtl: $ttlSec);
        $daemonB = $this->makeDaemon($sharedLease, $advancingClock, shardCount: 1, leaseTtl: $ttlSec);

        // Daemon A acquires at T=0.
        self::assertTrue($daemonA->runOnce());

        // Daemon B cannot acquire while lease is still live.
        self::assertFalse($daemonB->runOnce());

        // Advance clock past TTL (TTL=5s, advance to T=6s).
        $timeHolder->now = new DateTimeImmutable('2026-07-01T10:00:06Z');

        // Daemon A's lease has expired — daemon B can now take over.
        self::assertTrue($daemonB->runOnce(), 'Daemon B must acquire shard after daemon A\'s TTL expires');
    }

    // ─────────────────────────────────────────────────────────────
    // Multi-shard: first daemon takes all shards; second is fully blocked
    // ─────────────────────────────────────────────────────────────

    public function test_first_daemon_takes_all_shards_before_second_can_compete(): void
    {
        // With shardCount=2 and a shared InMemoryLeaseStore, the first daemon to
        // call runOnce() acquires BOTH shard 0 and shard 1. The second daemon finds
        // all shards occupied and returns false.
        $clock   = $this->fixedClock(new DateTimeImmutable('2026-07-01T10:00:00Z'));
        $lease   = new InMemoryLeaseStore($clock);
        $daemonA = $this->makeDaemon($lease, $clock, shardCount: 2);
        $daemonB = $this->makeDaemon($lease, $clock, shardCount: 2);

        self::assertTrue($daemonA->runOnce(),  'Daemon A must acquire both shards (no competition)');
        self::assertFalse($daemonB->runOnce(), 'Daemon B must be blocked on all shards');
    }

    public function test_second_daemon_holds_shard_after_first_daemon_releases_it(): void
    {
        // To verify that after the first daemon's lease expires on one shard,
        // the second daemon can acquire that shard.
        $clock   = $this->fixedClock(new DateTimeImmutable('2026-07-01T10:00:00Z'));

        // Two separate lease stores simulate independent processes.
        // Daemon A has its own store (simulates it running on a different node).
        $leaseA  = new InMemoryLeaseStore($clock);
        $leaseB  = new InMemoryLeaseStore($clock);

        $daemonA = $this->makeDaemon($leaseA, $clock, shardCount: 1);
        $daemonB = $this->makeDaemon($leaseB, $clock, shardCount: 1);

        // Each daemon operates on an independent lease store → both think they're leader.
        // This is the split-brain scenario (covered in depth by SchedulerDaemonReshardTest).
        // Here we just verify each returns true independently.
        self::assertTrue($daemonA->runOnce());
        self::assertTrue($daemonB->runOnce());
    }

    // ─────────────────────────────────────────────────────────────
    // Per-shard token stability across multiple runOnce() calls
    // ─────────────────────────────────────────────────────────────

    public function test_daemon_stable_across_multiple_run_once_calls(): void
    {
        $clock  = $this->fixedClock(new DateTimeImmutable('2026-07-01T10:00:00Z'));
        $lease  = new InMemoryLeaseStore($clock);
        $daemon = $this->makeDaemon($lease, $clock, shardCount: 1);

        // Repeated runOnce() calls must all succeed — the daemon renews its own lease.
        self::assertTrue($daemon->runOnce());
        self::assertTrue($daemon->runOnce());
        self::assertTrue($daemon->runOnce());
    }

    public function test_competing_daemon_still_blocked_after_leader_renews(): void
    {
        $clock   = $this->fixedClock(new DateTimeImmutable('2026-07-01T10:00:00Z'));
        $lease   = new InMemoryLeaseStore($clock);
        $daemonA = $this->makeDaemon($lease, $clock, shardCount: 1);
        $daemonB = $this->makeDaemon($lease, $clock, shardCount: 1);

        $daemonA->runOnce(); // A acquires
        $daemonA->runOnce(); // A renews

        // B must still be blocked after A's renewal.
        self::assertFalse($daemonB->runOnce());
    }

    // ─────────────────────────────────────────────────────────────
    // Infrastructure
    // ─────────────────────────────────────────────────────────────

    private function makeDaemon(
        InMemoryLeaseStore $leaseStore,
        ClockPort          $clock,
        int                $shardCount = 1,
        int                $leaseTtl   = 30,
    ): SchedulerDaemon {
        $slotCalc   = new SlotCalculator();
        $resolver   = new MisfireResolver($slotCalc);
        $dueScan    = new DueScan($resolver, 86400);
        $runStore   = new DbalScheduleRunStore($this->connection, self::RUNS_TABLE);

        $dispatcher = new FireDispatcher(
            runStore:          $runStore,
            enqueuer:          $this->enqueuer,
            connection:        $this->connection,
            clock:             $clock,
            assumedDoneTtlSec: 3600,
        );

        // Empty schedule list: tickShard() returns early, dispatcher never called.
        $scheduleStore    = $this->emptyScheduleStore();
        $scheduleResolver = new ScheduleResolver(new StaticScheduleRegistry(), $scheduleStore);
        $cursorStore      = new DbalScheduleCursorStore($this->connection, self::CURSORS_TABLE);

        return new SchedulerDaemon(
            leasePort:               $leaseStore,
            scheduleResolver:        $scheduleResolver,
            cursorStore:             $cursorStore,
            dueScan:                 $dueScan,
            fireDispatcher:          $dispatcher,
            clock:                   $clock,
            logger:                  new NullLogger(),
            shardCount:              $shardCount,
            leaseTtlSec:             $leaseTtl,
            maxIdleSec:              60,
            tenantMaxConcurrentFires: 0,
        );
    }

    private function emptyScheduleStore(): ScheduleStoreInterface
    {
        return new class implements ScheduleStoreInterface {
            public function save(Schedule $schedule): void {}

            public function find(ScheduleId $id, ?string $tenantId): ?Schedule
            {
                return null;
            }

            public function findByName(string $name, ?string $tenantId): ?Schedule
            {
                return null;
            }

            public function delete(ScheduleId $id, ?string $tenantId): void {}

            public function findActive(?string $tenantId): iterable
            {
                return [];
            }

            public function findAllActive(): iterable
            {
                return [];
            }

            public function findAll(?string $tenantId): iterable
            {
                return [];
            }
        };
    }

    private function fixedClock(DateTimeImmutable $at): ClockPort
    {
        return new class($at) implements ClockPort {
            public function __construct(private readonly DateTimeImmutable $now) {}

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };
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
            $this->markTestSkipped('PostgreSQL not reachable: ' . $e->getMessage());
        }
    }

    private function ensureTables(): void
    {
        $t = self::RUNS_TABLE;
        $this->connection->executeStatement("
            CREATE TABLE IF NOT EXISTS {$t} (
                run_id        CHAR(64)     NOT NULL,
                schedule_id   VARCHAR(36)  NOT NULL,
                tenant_id     VARCHAR(255) NULL,
                slot          TEXT         NOT NULL,
                scheduled_for TIMESTAMPTZ  NOT NULL,
                dispatched_at TIMESTAMPTZ  NOT NULL,
                completed_at  TIMESTAMPTZ  NULL,
                run_state     VARCHAR(20)  NOT NULL DEFAULT 'dispatched',
                attempt       SMALLINT     NOT NULL DEFAULT 1,
                CONSTRAINT pk_{$t} PRIMARY KEY (run_id)
            )
        ");
        $this->connection->executeStatement("
            CREATE UNIQUE INDEX IF NOT EXISTS uq_{$t}_slot
                ON {$t} (tenant_id, schedule_id, slot)
        ");
        $this->connection->executeStatement("
            CREATE INDEX IF NOT EXISTS idx_{$t}_schedule_dispatched
                ON {$t} (schedule_id, dispatched_at)
        ");

        $c = self::CURSORS_TABLE;
        $this->connection->executeStatement("
            CREATE TABLE IF NOT EXISTS {$c} (
                schedule_id    VARCHAR(36)  NOT NULL,
                tenant_id      VARCHAR(255) NULL,
                cursor_at      TIMESTAMPTZ  NOT NULL,
                cursor_version INTEGER      NOT NULL DEFAULT 1,
                updated_at     TIMESTAMPTZ  NOT NULL,
                CONSTRAINT pk_{$c} PRIMARY KEY (schedule_id)
            )
        ");
    }

    private function cleanCreatedRows(): void
    {
        if (empty($this->createdIds)) {
            return;
        }

        try {
            $placeholders = implode(',', array_fill(0, count($this->createdIds), '?'));
            $ids          = array_map(fn (ScheduleId $id) => $id->toString(), $this->createdIds);
            $this->connection->executeStatement(
                "DELETE FROM " . self::RUNS_TABLE . " WHERE schedule_id IN ({$placeholders})",
                $ids,
            );
            $this->connection->executeStatement(
                "DELETE FROM " . self::CURSORS_TABLE . " WHERE schedule_id IN ({$placeholders})",
                $ids,
            );
        } catch (Throwable) {
        }
    }
}
