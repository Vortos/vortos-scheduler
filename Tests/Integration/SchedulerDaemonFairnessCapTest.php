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
use Vortos\Scheduler\Store\Dbal\DbalScheduleRunStore;
use Vortos\Scheduler\Store\ScheduleStoreInterface;
use Vortos\Scheduler\Testing\RecordingSchedulerEnqueuer;

/**
 * Integration tests for the per-tenant fairness cap in SchedulerDaemon.
 *
 * The cap is controlled by $tenantMaxConcurrentFires (env: SCHEDULER_TENANT_MAX_CONCURRENT_FIRES):
 *  - 0  = unlimited (no throttling)
 *  - N  = at most N fires dispatched per tenant per tick
 *
 * Fires exceeding the cap are silently skipped (logged but not re-queued for that tick).
 * The next tick picks them up if they are still due.
 *
 * Tenant buckets are keyed by tenantId; null tenantId maps to '' (empty-string bucket).
 * System schedules (tenantId=null) and tenant '' share the same fairness bucket.
 *
 * Covers:
 *  - cap=0: all fires dispatched regardless of tenant
 *  - cap=1: exactly 1 fire per tenant per tick
 *  - cap=2: exactly 2 fires per tenant per tick
 *  - Multi-tenant: cap applied independently per tenant
 *  - System schedules (tenantId=null) bucketed as ''
 *  - Exact throttled count verified (total = cap × tenant count)
 */
final class SchedulerDaemonFairnessCapTest extends TestCase
{
    private const RUNS_TABLE = 'vortos_scheduler_runs';

    private Connection                 $connection;
    private RecordingSchedulerEnqueuer $enqueuer;
    private ClockPort                  $clock;

    /** @var list<ScheduleId> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        $this->connection = $this->connectOrSkip();
        $this->enqueuer   = new RecordingSchedulerEnqueuer();
        $this->clock      = $this->fixedClock(new DateTimeImmutable('2026-07-01T10:00:00Z'));
        $this->ensureTables();
    }

    protected function tearDown(): void
    {
        $this->cleanCreatedRows();
    }

    // ─────────────────────────────────────────────────────────────
    // Unlimited (cap = 0)
    // ─────────────────────────────────────────────────────────────

    public function test_cap_zero_dispatches_all_fires(): void
    {
        $schedules = $this->makeScheduleBatch('cap-zero', 'tenant-a', 5);

        $daemon = $this->makeDaemon($schedules, tenantMaxConcurrentFires: 0);
        $daemon->runOnce();

        self::assertSame(5, $this->enqueuer->count(), 'cap=0 must dispatch all 5 fires (unlimited)');
    }

    public function test_cap_zero_with_multiple_tenants_dispatches_all(): void
    {
        $schedules = [
            ...$this->makeScheduleBatch('cap-zero-a', 'tenant-a', 3),
            ...$this->makeScheduleBatch('cap-zero-b', 'tenant-b', 3),
        ];

        $daemon = $this->makeDaemon($schedules, tenantMaxConcurrentFires: 0);
        $daemon->runOnce();

        self::assertSame(6, $this->enqueuer->count(), 'cap=0 must dispatch all fires across all tenants');
    }

    // ─────────────────────────────────────────────────────────────
    // Cap = 1
    // ─────────────────────────────────────────────────────────────

    public function test_cap_one_dispatches_exactly_one_fire_per_tenant(): void
    {
        $schedules = $this->makeScheduleBatch('cap-one', 'tenant-a', 5);

        $daemon = $this->makeDaemon($schedules, tenantMaxConcurrentFires: 1);
        $daemon->runOnce();

        self::assertSame(1, $this->enqueuer->count(), 'cap=1 must dispatch exactly 1 fire for tenant-a');
    }

    public function test_cap_one_with_two_tenants_dispatches_one_per_tenant(): void
    {
        $schedules = [
            ...$this->makeScheduleBatch('cap-one-a', 'tenant-a', 4),
            ...$this->makeScheduleBatch('cap-one-b', 'tenant-b', 4),
        ];

        $daemon = $this->makeDaemon($schedules, tenantMaxConcurrentFires: 1);
        $daemon->runOnce();

        // 1 for tenant-a + 1 for tenant-b = 2 total.
        self::assertSame(2, $this->enqueuer->count(), 'cap=1 must dispatch 1 per tenant (2 tenants → 2 total)');

        $tenantIds = array_map(fn ($f) => $f->tenantId, $this->enqueuer->firedSlots());
        self::assertContains('tenant-a', $tenantIds);
        self::assertContains('tenant-b', $tenantIds);
    }

    // ─────────────────────────────────────────────────────────────
    // Cap = 2
    // ─────────────────────────────────────────────────────────────

    public function test_cap_two_dispatches_exactly_two_fires_per_tenant(): void
    {
        $schedules = $this->makeScheduleBatch('cap-two', 'tenant-a', 5);

        $daemon = $this->makeDaemon($schedules, tenantMaxConcurrentFires: 2);
        $daemon->runOnce();

        self::assertSame(2, $this->enqueuer->count(), 'cap=2 must dispatch exactly 2 fires for tenant-a');
    }

    public function test_cap_two_with_two_tenants_dispatches_two_per_tenant(): void
    {
        $schedules = [
            ...$this->makeScheduleBatch('cap-two-a', 'tenant-a', 5),
            ...$this->makeScheduleBatch('cap-two-b', 'tenant-b', 5),
        ];

        $daemon = $this->makeDaemon($schedules, tenantMaxConcurrentFires: 2);
        $daemon->runOnce();

        self::assertSame(4, $this->enqueuer->count(), 'cap=2 with 2 tenants must dispatch 2+2=4');

        $tenantFireCounts = array_count_values(
            array_map(fn ($f) => $f->tenantId, $this->enqueuer->firedSlots()),
        );

        self::assertSame(2, $tenantFireCounts['tenant-a'], 'tenant-a must have exactly 2 fires');
        self::assertSame(2, $tenantFireCounts['tenant-b'], 'tenant-b must have exactly 2 fires');
    }

    public function test_cap_two_with_three_tenants_dispatches_two_per_tenant(): void
    {
        $schedules = [
            ...$this->makeScheduleBatch('cap-three-a', 'tenant-a', 5),
            ...$this->makeScheduleBatch('cap-three-b', 'tenant-b', 5),
            ...$this->makeScheduleBatch('cap-three-c', 'tenant-c', 5),
        ];

        $daemon = $this->makeDaemon($schedules, tenantMaxConcurrentFires: 2);
        $daemon->runOnce();

        self::assertSame(6, $this->enqueuer->count(), 'cap=2 with 3 tenants must dispatch 2+2+2=6');

        $tenantFireCounts = array_count_values(
            array_map(fn ($f) => $f->tenantId, $this->enqueuer->firedSlots()),
        );

        foreach (['tenant-a', 'tenant-b', 'tenant-c'] as $t) {
            self::assertSame(2, $tenantFireCounts[$t] ?? 0, "{$t} must have exactly 2 fires with cap=2");
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Cap exactly at boundary
    // ─────────────────────────────────────────────────────────────

    public function test_cap_equal_to_schedule_count_dispatches_all(): void
    {
        $schedules = $this->makeScheduleBatch('cap-exact', 'tenant-a', 3);

        $daemon = $this->makeDaemon($schedules, tenantMaxConcurrentFires: 3);
        $daemon->runOnce();

        self::assertSame(3, $this->enqueuer->count(), 'cap equal to schedule count must dispatch all');
    }

    public function test_cap_greater_than_schedule_count_dispatches_all(): void
    {
        $schedules = $this->makeScheduleBatch('cap-over', 'tenant-a', 3);

        $daemon = $this->makeDaemon($schedules, tenantMaxConcurrentFires: 10);
        $daemon->runOnce();

        self::assertSame(3, $this->enqueuer->count(), 'cap greater than schedule count must dispatch all');
    }

    // ─────────────────────────────────────────────────────────────
    // System schedules (tenantId = null)
    // ─────────────────────────────────────────────────────────────

    public function test_system_schedules_bucketed_as_empty_string(): void
    {
        // tenantId=null maps to '' bucket in the daemon. With cap=2 and 4 system schedules,
        // only 2 are dispatched.
        $schedules = $this->makeScheduleBatch('sys-sched', null, 4);

        $daemon = $this->makeDaemon($schedules, tenantMaxConcurrentFires: 2);
        $daemon->runOnce();

        self::assertSame(2, $this->enqueuer->count(), 'System schedules (tenantId=null) must respect cap');
    }

    public function test_system_and_tenant_schedules_have_independent_buckets(): void
    {
        $schedules = [
            ...$this->makeScheduleBatch('sys-mixed', null, 3),
            ...$this->makeScheduleBatch('tenant-mixed', 'tenant-a', 3),
        ];

        $daemon = $this->makeDaemon($schedules, tenantMaxConcurrentFires: 2);
        $daemon->runOnce();

        // 2 for null bucket (system) + 2 for tenant-a bucket = 4 total.
        self::assertSame(4, $this->enqueuer->count(), 'system and tenant-a must each respect cap=2 independently');
    }

    // ─────────────────────────────────────────────────────────────
    // Infrastructure
    // ─────────────────────────────────────────────────────────────

    /** @param list<Schedule> $schedules */
    private function makeDaemon(array $schedules, int $tenantMaxConcurrentFires): SchedulerDaemon
    {
        $clock      = $this->clock;
        $leaseStore = new InMemoryLeaseStore($clock);
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

        $scheduleStore    = $this->makeScheduleStore($schedules);
        $scheduleResolver = new ScheduleResolver(new StaticScheduleRegistry(), $scheduleStore);

        return new SchedulerDaemon(
            leasePort:               $leaseStore,
            scheduleResolver:        $scheduleResolver,
            runStore:                $runStore,
            dueScan:                 $dueScan,
            fireDispatcher:          $dispatcher,
            clock:                   $clock,
            logger:                  new NullLogger(),
            shardCount:              1,
            leaseTtlSec:             30,
            maxIdleSec:              60,
            tenantMaxConcurrentFires: $tenantMaxConcurrentFires,
        );
    }

    /**
     * Creates $count schedules for the given tenant, all with a due fire 30s in the past.
     *
     * @return list<Schedule>
     */
    private function makeScheduleBatch(string $namePrefix, ?string $tenantId, int $count): array
    {
        $fireAt    = (new DateTimeImmutable('2026-07-01T10:00:00Z'))->modify('-30 seconds');
        $schedules = [];

        for ($i = 0; $i < $count; $i++) {
            $id = ScheduleId::generate();
            $this->createdIds[] = $id;

            $schedules[] = new Schedule(
                id:       $id,
                name:     "{$namePrefix}-{$i}",
                source:   ScheduleSource::Static,
                trigger:  new OneShotTrigger($fireAt),
                command:  new CommandSpec('Vortos\Scheduler\Tests\Integration\FakeCommand'),
                misfire:  MisfirePolicy::skipMissed(),
                overlap:  OverlapPolicy::AllowConcurrent,
                timezone: new DateTimeZone('UTC'),
                jitter:   null,
                status:   ScheduleStatus::Active,
                tenantId: $tenantId,
            );
        }

        return $schedules;
    }

    /** @param list<Schedule> $schedules */
    private function makeScheduleStore(array $schedules): ScheduleStoreInterface
    {
        return new class($schedules) implements ScheduleStoreInterface {
            public function __construct(private readonly array $schedules) {}

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
                return $this->schedules;
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
        } catch (Throwable) {
        }
    }
}
