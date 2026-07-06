<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Doctor;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Doctor\SchedulerDoctor;
use Vortos\Scheduler\Doctor\SchedulerDoctorStatus;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Lease\Driver\InMemoryLeaseStore;
use Vortos\Scheduler\Registry\ScheduleResolver;
use Vortos\Scheduler\Registry\StaticScheduleRegistry;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;
use Vortos\Scheduler\Security\CommandSpecValidator;
use Vortos\Scheduler\Testing\InMemoryScheduleStatusOverrideStore;
use Vortos\Scheduler\Testing\InMemoryScheduleStore;

/**
 * @covers \Vortos\Scheduler\Doctor\SchedulerDoctor
 */
final class SchedulerDoctorTest extends TestCase
{
    private const TABLE_PREFIX = 'vortos_';

    private InMemoryLeaseStore $leaseStore;
    private MutableClock $clock;
    private InMemoryScheduleStore $dynamicStore;

    protected function setUp(): void
    {
        $this->clock        = new MutableClock(new DateTimeImmutable('2026-07-01T12:00:00+00:00'));
        $this->leaseStore   = new InMemoryLeaseStore($this->clock);
        $this->dynamicStore = new InMemoryScheduleStore();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeSchedule(
        string $name = 'test-schedule',
        bool $sensitive = false,
        MisfirePolicy $misfire = null,
        array $metadata = [],
    ): Schedule {
        return new Schedule(
            id:        ScheduleId::generate(),
            name:      $name,
            source:    ScheduleSource::Dynamic,
            trigger:   new IntervalTrigger(3600),
            command:   new CommandSpec('App\Command\TestCommand'),
            misfire:   $misfire ?? MisfirePolicy::skipMissed(),
            overlap:   OverlapPolicy::AllowConcurrent,
            timezone:  new DateTimeZone('UTC'),
            jitter:    null,
            status:    ScheduleStatus::Active,
            tenantId:  null,
            sensitive: $sensitive,
            metadata:  $metadata,
        );
    }

    private function makeSqliteConnection(array $tables = []): \Doctrine\DBAL\Connection
    {
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ($tables as $table) {
            $conn->executeStatement("CREATE TABLE {$table} (id INTEGER PRIMARY KEY)");
        }
        return $conn;
    }

    private function makeDoctor(
        StaticScheduleRegistry $registry = null,
        \Doctrine\DBAL\Connection $conn = null,
        CommandSpecValidator $validator = null,
        \Vortos\Scheduler\Security\Approval\FourEyesApprovalStoreInterface $approvalStore = null,
        int $shardCount = 1,
        int $maxCatchupAgeSec = 86400,
        int $runRetentionDays = 0,
        \Vortos\Scheduler\Store\RunRetentionOverrideStoreInterface $retentionOverrideStore = null,
        ?object $commandBus = null,
        int $consumeStallThresholdSec = 120,
    ): SchedulerDoctor {
        $reg      = $registry ?? new StaticScheduleRegistry([]);
        $overrides = new InMemoryScheduleStatusOverrideStore();
        $resolver  = new ScheduleResolver($reg, $this->dynamicStore, $overrides);
        $conn      = $conn ?? $this->makeSqliteConnection();

        return new SchedulerDoctor(
            resolver:                    $resolver,
            dynamicStore:                $this->dynamicStore,
            leasePort:                   $this->leaseStore,
            connection:                  $conn,
            clock:                       $this->clock,
            validator:                   $validator,
            approvalStore:               $approvalStore,
            tablePrefix:                 self::TABLE_PREFIX,
            shardCount:                  $shardCount,
            maxCatchupAgeSec:            $maxCatchupAgeSec,
            runRetentionDays:            $runRetentionDays,
            retentionOverrideStore:     $retentionOverrideStore,
            commandBus:                 $commandBus,
            consumeStallThresholdSec:   $consumeStallThresholdSec,
        );
    }

    private function makeRunsAndQueueTables(\Doctrine\DBAL\Connection $conn): void
    {
        $conn->executeStatement('
            CREATE TABLE vortos_scheduler_runs (
                run_id CHAR(64) NOT NULL PRIMARY KEY,
                schedule_id VARCHAR(36) NOT NULL,
                tenant_id VARCHAR(255) NULL,
                slot TEXT NOT NULL,
                scheduled_for DATETIME NOT NULL,
                dispatched_at DATETIME NOT NULL,
                completed_at DATETIME NULL,
                run_state VARCHAR(20) NOT NULL DEFAULT "dispatched",
                attempt SMALLINT NOT NULL DEFAULT 1
            )
        ');
        $conn->executeStatement('
            CREATE TABLE vortos_scheduler_fire_queue (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                status VARCHAR(20) NOT NULL DEFAULT "pending",
                command_class TEXT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                available_at DATETIME NULL,
                last_error TEXT NULL,
                dispatched_at DATETIME NULL,
                created_at DATETIME NOT NULL
            )
        ');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // C1 — Cron expressions are valid
    // ══════════════════════════════════════════════════════════════════════════

    public function test_c1_passes_when_all_triggers_are_valid(): void
    {
        $this->dynamicStore->seed($this->makeSchedule());
        $report = $this->makeDoctor()->run();
        $c1 = $this->findCheck($report->findings, 'C1');
        self::assertSame(SchedulerDoctorStatus::Pass, $c1->status);
    }

    public function test_c1_passes_with_no_schedules(): void
    {
        $report = $this->makeDoctor()->run();
        $c1 = $this->findCheck($report->findings, 'C1');
        self::assertSame(SchedulerDoctorStatus::Pass, $c1->status);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // C2 — No name collision
    // ══════════════════════════════════════════════════════════════════════════

    public function test_c2_passes_when_no_collision(): void
    {
        $this->dynamicStore->seed($this->makeSchedule('dynamic-only'));
        $report = $this->makeDoctor()->run();
        $c2 = $this->findCheck($report->findings, 'C2');
        self::assertSame(SchedulerDoctorStatus::Pass, $c2->status);
    }

    public function test_c2_fails_on_name_collision(): void
    {
        // Static schedule has name 'test-static-schedule'
        $registry = new StaticScheduleRegistry([\Vortos\Scheduler\Tests\Unit\Service\Support\FixedStaticScheduleDefinition::class]);

        // Dynamic with same system-scoped name
        $dynamic = new Schedule(
            id:        ScheduleId::generate(),
            name:      \Vortos\Scheduler\Tests\Unit\Service\Support\FixedStaticScheduleDefinition::SCHEDULE_NAME,
            source:    ScheduleSource::Dynamic,
            trigger:   new IntervalTrigger(3600),
            command:   new CommandSpec('App\Command\TestCommand'),
            misfire:   MisfirePolicy::skipMissed(),
            overlap:   OverlapPolicy::AllowConcurrent,
            timezone:  new DateTimeZone('UTC'),
            jitter:    null,
            status:    ScheduleStatus::Active,
            tenantId:  null,
        );
        $this->dynamicStore->seed($dynamic);

        $report = $this->makeDoctor($registry)->run();
        $c2 = $this->findCheck($report->findings, 'C2');
        self::assertSame(SchedulerDoctorStatus::Fail, $c2->status);
        self::assertStringContainsString('collision', strtolower($c2->summary));
    }

    // ══════════════════════════════════════════════════════════════════════════
    // C3 — Command allowlist
    // ══════════════════════════════════════════════════════════════════════════

    public function test_c3_skipped_when_no_validator(): void
    {
        $report = $this->makeDoctor(validator: null)->run();
        $c3 = $this->findCheck($report->findings, 'C3');
        self::assertSame(SchedulerDoctorStatus::Skip, $c3->status);
    }

    public function test_c3_passes_when_all_commands_allowlisted(): void
    {
        $schedule = $this->makeSchedule('allowed-job');
        $this->dynamicStore->seed($schedule);

        $validator = new CommandSpecValidator(['App\Command\TestCommand' => true]);
        $report    = $this->makeDoctor(validator: $validator)->run();
        $c3        = $this->findCheck($report->findings, 'C3');
        self::assertSame(SchedulerDoctorStatus::Pass, $c3->status);
    }

    public function test_c3_fails_when_command_not_allowlisted(): void
    {
        $schedule = $this->makeSchedule('disallowed-job');
        $this->dynamicStore->seed($schedule);

        $validator = new CommandSpecValidator([]);  // empty allowlist — all commands fail
        $report    = $this->makeDoctor(validator: $validator)->run();
        $c3        = $this->findCheck($report->findings, 'C3');
        self::assertSame(SchedulerDoctorStatus::Fail, $c3->status);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // C4 — Lease driver reachable
    // ══════════════════════════════════════════════════════════════════════════

    public function test_c4_passes_when_in_memory_lease_store_reachable(): void
    {
        $report = $this->makeDoctor()->run();
        $c4 = $this->findCheck($report->findings, 'C4');
        self::assertSame(SchedulerDoctorStatus::Pass, $c4->status);
    }

    public function test_c4_fails_when_lease_store_throws(): void
    {
        $throwingLease = new class implements \Vortos\Scheduler\Lease\LeasePort {
            public function acquire(string $key, \Vortos\Scheduler\Lease\LeaseToken $token, int $ttlSeconds): ?\Vortos\Scheduler\Lease\Lease
            {
                throw new \RuntimeException('Connection refused');
            }
            public function renew(\Vortos\Scheduler\Lease\Lease $lease, int $ttlSeconds): \Vortos\Scheduler\Lease\Lease
            {
                throw new \RuntimeException('Connection refused');
            }
            public function release(\Vortos\Scheduler\Lease\Lease $lease): void {}
        };

        $overrides = new InMemoryScheduleStatusOverrideStore();
        $resolver  = new ScheduleResolver(new StaticScheduleRegistry([]), $this->dynamicStore, $overrides);
        $doctor    = new SchedulerDoctor(
            resolver:         $resolver,
            dynamicStore:     $this->dynamicStore,
            leasePort:        $throwingLease,
            connection:       $this->makeSqliteConnection(),
            clock:            $this->clock,
            validator:        null,
            approvalStore:    null,
            tablePrefix:      self::TABLE_PREFIX,
            shardCount:       1,
            maxCatchupAgeSec: 86400,
        );

        $report = $doctor->run();
        $c4 = $this->findCheck($report->findings, 'C4');
        self::assertSame(SchedulerDoctorStatus::Fail, $c4->status);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // C5 — Migrations applied
    // ══════════════════════════════════════════════════════════════════════════

    public function test_c5_passes_when_all_tables_present(): void
    {
        $tables = [
            self::TABLE_PREFIX . 'scheduler_schedules',
            self::TABLE_PREFIX . 'scheduler_runs',
            self::TABLE_PREFIX . 'scheduler_audit_log',
            self::TABLE_PREFIX . 'scheduler_audit_checkpoints',
            self::TABLE_PREFIX . 'scheduler_static_overrides',
            self::TABLE_PREFIX . 'scheduler_fire_queue',
            self::TABLE_PREFIX . 'scheduler_run_retention_overrides',
        ];
        $conn = $this->makeSqliteConnection($tables);
        $report = $this->makeDoctor(conn: $conn)->run();
        $c5 = $this->findCheck($report->findings, 'C5');
        self::assertSame(SchedulerDoctorStatus::Pass, $c5->status);
    }

    public function test_c5_fails_when_table_missing(): void
    {
        $conn = $this->makeSqliteConnection([]);
        $report = $this->makeDoctor(conn: $conn)->run();
        $c5 = $this->findCheck($report->findings, 'C5');
        self::assertSame(SchedulerDoctorStatus::Fail, $c5->status);
        self::assertStringContainsString('missing', strtolower($c5->summary));
    }

    // ══════════════════════════════════════════════════════════════════════════
    // C6 — Sensitive approvals present
    // ══════════════════════════════════════════════════════════════════════════

    public function test_c6_skipped_when_no_approval_store(): void
    {
        $report = $this->makeDoctor(approvalStore: null)->run();
        $c6 = $this->findCheck($report->findings, 'C6');
        self::assertSame(SchedulerDoctorStatus::Skip, $c6->status);
    }

    public function test_c6_passes_when_no_sensitive_schedules(): void
    {
        $this->dynamicStore->seed($this->makeSchedule(sensitive: false));
        $approvalStore = $this->makeFakeApprovalStore([]);

        $report = $this->makeDoctor(approvalStore: $approvalStore)->run();
        $c6 = $this->findCheck($report->findings, 'C6');
        self::assertSame(SchedulerDoctorStatus::Pass, $c6->status);
    }

    public function test_c6_fails_when_sensitive_schedule_lacks_approval(): void
    {
        $schedule = $this->makeSchedule('sensitive-job', sensitive: true);
        $this->dynamicStore->seed($schedule);
        $approvalStore = $this->makeFakeApprovalStore([]);

        $report = $this->makeDoctor(approvalStore: $approvalStore)->run();
        $c6 = $this->findCheck($report->findings, 'C6');
        self::assertSame(SchedulerDoctorStatus::Fail, $c6->status);
    }

    public function test_c6_passes_when_sensitive_schedule_has_approval(): void
    {
        $schedule = $this->makeSchedule('sensitive-job', sensitive: true);
        $this->dynamicStore->seed($schedule);

        $request = $this->makeApprovalRequest($schedule->id);
        $approvalStore = $this->makeFakeApprovalStore([$schedule->id->toString() => [$request]]);

        $report = $this->makeDoctor(approvalStore: $approvalStore)->run();
        $c6 = $this->findCheck($report->findings, 'C6');
        self::assertSame(SchedulerDoctorStatus::Pass, $c6->status);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // C7 — Sensitive explicit misfire policy
    // ══════════════════════════════════════════════════════════════════════════

    public function test_c7_passes_when_no_sensitive_schedules(): void
    {
        $this->dynamicStore->seed($this->makeSchedule(sensitive: false));
        $report = $this->makeDoctor()->run();
        $c7 = $this->findCheck($report->findings, 'C7');
        self::assertSame(SchedulerDoctorStatus::Pass, $c7->status);
    }

    public function test_c7_fails_when_sensitive_uses_implicit_skip_missed(): void
    {
        $schedule = $this->makeSchedule('sensitive-job', sensitive: true, misfire: MisfirePolicy::skipMissed());
        $this->dynamicStore->seed($schedule);

        $report = $this->makeDoctor()->run();
        $c7 = $this->findCheck($report->findings, 'C7');
        self::assertSame(SchedulerDoctorStatus::Fail, $c7->status);
    }

    public function test_c7_passes_when_sensitive_has_explicit_misfire_policy_flag(): void
    {
        $schedule = $this->makeSchedule('sensitive-job', sensitive: true, metadata: ['misfire_policy_explicit' => 'true']);
        $this->dynamicStore->seed($schedule);

        $report = $this->makeDoctor()->run();
        $c7 = $this->findCheck($report->findings, 'C7');
        self::assertSame(SchedulerDoctorStatus::Pass, $c7->status);
    }

    public function test_c7_passes_when_sensitive_uses_fire_once_now(): void
    {
        $schedule = $this->makeSchedule('sensitive-job', sensitive: true, misfire: MisfirePolicy::fireOnceNow());
        $this->dynamicStore->seed($schedule);

        $report = $this->makeDoctor()->run();
        $c7 = $this->findCheck($report->findings, 'C7');
        self::assertSame(SchedulerDoctorStatus::Pass, $c7->status);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // C8 — Catchup bounds valid
    // ══════════════════════════════════════════════════════════════════════════

    public function test_c8_passes_with_valid_catchup_age(): void
    {
        $report = $this->makeDoctor(maxCatchupAgeSec: 86400)->run();
        $c8 = $this->findCheck($report->findings, 'C8');
        self::assertSame(SchedulerDoctorStatus::Pass, $c8->status);
    }

    public function test_c8_fails_when_max_catchup_age_is_zero(): void
    {
        $report = $this->makeDoctor(maxCatchupAgeSec: 0)->run();
        $c8 = $this->findCheck($report->findings, 'C8');
        self::assertSame(SchedulerDoctorStatus::Fail, $c8->status);
    }

    public function test_c8_fails_when_max_catchup_age_is_negative(): void
    {
        $report = $this->makeDoctor(maxCatchupAgeSec: -1)->run();
        $c8 = $this->findCheck($report->findings, 'C8');
        self::assertSame(SchedulerDoctorStatus::Fail, $c8->status);
    }

    public function test_c8_passes_with_valid_fire_each_missed_cap(): void
    {
        $schedule = $this->makeSchedule(misfire: MisfirePolicy::fireEachMissed(50));
        $this->dynamicStore->seed($schedule);

        $report = $this->makeDoctor()->run();
        $c8 = $this->findCheck($report->findings, 'C8');
        self::assertSame(SchedulerDoctorStatus::Pass, $c8->status);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // C9 — Shard config valid
    // ══════════════════════════════════════════════════════════════════════════

    public function test_c9_passes_with_shard_count_one(): void
    {
        $report = $this->makeDoctor(shardCount: 1)->run();
        $c9 = $this->findCheck($report->findings, 'C9');
        self::assertSame(SchedulerDoctorStatus::Pass, $c9->status);
    }

    public function test_c9_fails_when_shard_count_is_zero(): void
    {
        $report = $this->makeDoctor(shardCount: 0)->run();
        $c9 = $this->findCheck($report->findings, 'C9');
        self::assertSame(SchedulerDoctorStatus::Fail, $c9->status);
    }

    public function test_c9_fails_when_shard_count_is_negative(): void
    {
        $report = $this->makeDoctor(shardCount: -1)->run();
        $c9 = $this->findCheck($report->findings, 'C9');
        self::assertSame(SchedulerDoctorStatus::Fail, $c9->status);
    }

    public function test_c9_passes_with_multiple_shards(): void
    {
        $report = $this->makeDoctor(shardCount: 3)->run();
        $c9 = $this->findCheck($report->findings, 'C9');
        self::assertSame(SchedulerDoctorStatus::Pass, $c9->status);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // full report structure
    // ══════════════════════════════════════════════════════════════════════════

    public function test_report_has_exactly_twelve_findings(): void
    {
        $report = $this->makeDoctor()->run();
        self::assertCount(12, $report->findings);
    }

    public function test_report_finding_ids_are_c1_through_c12(): void
    {
        $report = $this->makeDoctor()->run();
        $ids = array_map(fn($f) => $f->checkId, $report->findings);
        foreach (['C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'C8', 'C9', 'C10', 'C11', 'C12'] as $expected) {
            self::assertContains($expected, $ids);
        }
    }

    public function test_empty_setup_produces_mostly_passing_report(): void
    {
        // With no schedules and an in-memory lease store:
        // C1=Pass, C2=Pass, C3=Skip(no validator), C4=Pass, C5=Fail(no tables),
        // C6=Skip(no approval store), C7=Pass, C8=Pass, C9=Pass,
        // C10=Pass(runRetentionDays defaults to 0/disabled), C11=Skip(consumer not installed)
        $report = $this->makeDoctor()->run();
        $fails = array_filter($report->findings, fn($f) => $f->isFailure());
        // Only C5 (migrations) should fail in this setup
        self::assertCount(1, $fails);
        self::assertSame('C5', current($fails)->checkId);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // C10 — Auto-prune config + liveness
    // ══════════════════════════════════════════════════════════════════════════

    public function test_c10_passes_when_retention_disabled(): void
    {
        $report = $this->makeDoctor(runRetentionDays: 0)->run();
        $c10 = $this->findCheck($report->findings, 'C10');
        self::assertSame(SchedulerDoctorStatus::Pass, $c10->status);
        self::assertStringContainsString('disabled', $c10->summary);
    }

    public function test_c10_skips_when_never_fired(): void
    {
        $conn = $this->makeSqliteConnection();
        $this->makeRunsAndQueueTables($conn);

        $report = $this->makeDoctor(conn: $conn, runRetentionDays: 30)->run();
        $c10 = $this->findCheck($report->findings, 'C10');
        self::assertSame(SchedulerDoctorStatus::Skip, $c10->status);
    }

    public function test_c10_fails_when_stale(): void
    {
        $conn = $this->makeSqliteConnection();
        $this->makeRunsAndQueueTables($conn);

        $scheduleId = \Vortos\Scheduler\Registry\PruneSchedulerRunsSchedule::SCHEDULE_ID;
        $stale      = $this->clock->now()->modify('-72 hours')->format('Y-m-d H:i:s');

        $conn->insert('vortos_scheduler_runs', [
            'run_id' => str_repeat('a', 64), 'schedule_id' => $scheduleId, 'tenant_id' => null,
            'slot' => 's', 'scheduled_for' => $stale, 'dispatched_at' => $stale,
            'completed_at' => $stale, 'run_state' => 'completed', 'attempt' => 1,
        ]);

        $report = $this->makeDoctor(conn: $conn, runRetentionDays: 30)->run();
        $c10 = $this->findCheck($report->findings, 'C10');
        self::assertSame(SchedulerDoctorStatus::Fail, $c10->status);
    }

    public function test_c10_passes_when_recently_completed(): void
    {
        $conn = $this->makeSqliteConnection();
        $this->makeRunsAndQueueTables($conn);

        $scheduleId = \Vortos\Scheduler\Registry\PruneSchedulerRunsSchedule::SCHEDULE_ID;
        $recent     = $this->clock->now()->modify('-2 hours')->format('Y-m-d H:i:s');

        $conn->insert('vortos_scheduler_runs', [
            'run_id' => str_repeat('b', 64), 'schedule_id' => $scheduleId, 'tenant_id' => null,
            'slot' => 's', 'scheduled_for' => $recent, 'dispatched_at' => $recent,
            'completed_at' => $recent, 'run_state' => 'completed', 'attempt' => 1,
        ]);

        $report = $this->makeDoctor(conn: $conn, runRetentionDays: 30)->run();
        $c10 = $this->findCheck($report->findings, 'C10');
        self::assertSame(SchedulerDoctorStatus::Pass, $c10->status);
    }

    public function test_c10_reports_tenant_overrides(): void
    {
        $conn = $this->makeSqliteConnection();
        $this->makeRunsAndQueueTables($conn);

        $retentionStore = new \Vortos\Scheduler\Testing\InMemoryRunRetentionOverrideStore();
        $retentionStore->save(new \Vortos\Scheduler\Store\RunRetentionOverride('tenant-a', 90, 'admin', new DateTimeImmutable()));
        $retentionStore->save(new \Vortos\Scheduler\Store\RunRetentionOverride('tenant-hold', 0, 'admin', new DateTimeImmutable()));

        $recent = $this->clock->now()->modify('-2 hours')->format('Y-m-d H:i:s');
        $conn->insert('vortos_scheduler_runs', [
            'run_id' => str_repeat('c', 64), 'schedule_id' => \Vortos\Scheduler\Registry\PruneSchedulerRunsSchedule::SCHEDULE_ID,
            'tenant_id' => null, 'slot' => 's', 'scheduled_for' => $recent, 'dispatched_at' => $recent,
            'completed_at' => $recent, 'run_state' => 'completed', 'attempt' => 1,
        ]);

        $report = $this->makeDoctor(conn: $conn, runRetentionDays: 30, retentionOverrideStore: $retentionStore)->run();
        $c10 = $this->findCheck($report->findings, 'C10');
        self::assertStringContainsString('tenant-a', $c10->detail);
        self::assertStringContainsString('[legal hold]', $c10->detail);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // C11 — Fire-queue consumer liveness (S12)
    // ══════════════════════════════════════════════════════════════════════════

    public function test_c11_skips_when_consumer_not_installed(): void
    {
        $report = $this->makeDoctor(commandBus: null)->run();
        $c11 = $this->findCheck($report->findings, 'C11');
        self::assertSame(SchedulerDoctorStatus::Skip, $c11->status);
    }

    public function test_c11_passes_when_queue_empty(): void
    {
        $conn = $this->makeSqliteConnection();
        $this->makeRunsAndQueueTables($conn);

        $report = $this->makeDoctor(conn: $conn, commandBus: new \stdClass())->run();
        $c11 = $this->findCheck($report->findings, 'C11');
        self::assertSame(SchedulerDoctorStatus::Pass, $c11->status);
    }

    public function test_c11_passes_when_backlog_is_fresh(): void
    {
        $conn = $this->makeSqliteConnection();
        $this->makeRunsAndQueueTables($conn);

        $conn->insert('vortos_scheduler_fire_queue', [
            'id' => 'row-1', 'status' => 'pending',
            'created_at' => $this->clock->now()->modify('-5 seconds')->format('Y-m-d H:i:s'),
        ]);

        $report = $this->makeDoctor(conn: $conn, commandBus: new \stdClass(), consumeStallThresholdSec: 120)->run();
        $c11 = $this->findCheck($report->findings, 'C11');
        self::assertSame(SchedulerDoctorStatus::Pass, $c11->status);
    }

    public function test_c11_fails_when_backlog_is_stale(): void
    {
        $conn = $this->makeSqliteConnection();
        $this->makeRunsAndQueueTables($conn);

        $conn->insert('vortos_scheduler_fire_queue', [
            'id' => 'row-1', 'status' => 'pending',
            'created_at' => $this->clock->now()->modify('-1 hour')->format('Y-m-d H:i:s'),
        ]);

        $report = $this->makeDoctor(conn: $conn, commandBus: new \stdClass(), consumeStallThresholdSec: 120)->run();
        $c11 = $this->findCheck($report->findings, 'C11');
        self::assertSame(SchedulerDoctorStatus::Fail, $c11->status);
        self::assertStringContainsString('scheduler:consume', $c11->remediation);
    }

    public function test_c12_passes_when_no_dead_letters(): void
    {
        $conn = $this->makeSqliteConnection();
        $this->makeRunsAndQueueTables($conn);

        $report = $this->makeDoctor(conn: $conn, commandBus: new \stdClass())->run();
        $c12 = $this->findCheck($report->findings, 'C12');
        self::assertSame(SchedulerDoctorStatus::Pass, $c12->status);
    }

    public function test_c12_fails_when_a_fire_is_dead_lettered(): void
    {
        $conn = $this->makeSqliteConnection();
        $this->makeRunsAndQueueTables($conn);

        $conn->insert('vortos_scheduler_fire_queue', [
            'id' => 'row-dl', 'status' => 'dead_letter',
            'command_class' => 'App\\Shared\\RunDatabaseBackup',
            'attempts' => 10,
            'dispatched_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            'created_at' => $this->clock->now()->modify('-1 hour')->format('Y-m-d H:i:s'),
        ]);

        $report = $this->makeDoctor(conn: $conn, commandBus: new \stdClass())->run();
        $c12 = $this->findCheck($report->findings, 'C12');
        self::assertSame(SchedulerDoctorStatus::Fail, $c12->status);
        self::assertStringContainsString('RunDatabaseBackup', $c12->detail);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $findings */
    private function findCheck(array $findings, string $checkId): \Vortos\Scheduler\Doctor\SchedulerDoctorFinding
    {
        foreach ($findings as $f) {
            if ($f->checkId === $checkId) {
                return $f;
            }
        }
        throw new \LogicException("Check {$checkId} not found in report.");
    }

    /**
     * @param array<string, list<\Vortos\Scheduler\Security\Approval\ApprovalRequest>> $requestsByScheduleId
     */
    private function makeFakeApprovalStore(array $requestsByScheduleId): \Vortos\Scheduler\Security\Approval\FourEyesApprovalStoreInterface
    {
        return new class($requestsByScheduleId) implements \Vortos\Scheduler\Security\Approval\FourEyesApprovalStoreInterface {
            public function __construct(private readonly array $data) {}

            public function save(\Vortos\Scheduler\Security\Approval\ApprovalRequest $request): void {}

            public function findById(string $id): ?\Vortos\Scheduler\Security\Approval\ApprovalRequest
            {
                foreach ($this->data as $requests) {
                    foreach ($requests as $r) {
                        if ($r->id === $id) return $r;
                    }
                }
                return null;
            }

            public function findPending(\Vortos\Scheduler\Schedule\ScheduleId $scheduleId, \Vortos\Scheduler\Security\Approval\ApprovalAction $action): ?\Vortos\Scheduler\Security\Approval\ApprovalRequest
            {
                return null;
            }

            public function findBySchedule(\Vortos\Scheduler\Schedule\ScheduleId $scheduleId): array
            {
                return $this->data[$scheduleId->toString()] ?? [];
            }

            public function findAllPending(?string $tenantId = null): array
            {
                return array_merge(...array_values($this->data));
            }

            public function expireStaleBefore(\DateTimeImmutable $cutoff): int
            {
                return 0;
            }
        };
    }

    private function makeApprovalRequest(ScheduleId $scheduleId): \Vortos\Scheduler\Security\Approval\ApprovalRequest
    {
        return new \Vortos\Scheduler\Security\Approval\ApprovalRequest(
            id:          'req-' . bin2hex(random_bytes(4)),
            scheduleId:  $scheduleId,
            action:      \Vortos\Scheduler\Security\Approval\ApprovalAction::Activate,
            status:      \Vortos\Scheduler\Security\Approval\ApprovalStatus::Approved,
            requestedBy: 'requester-1',
            requestedAt: new DateTimeImmutable('2026-07-01T10:00:00+00:00'),
            expiresAt:   new DateTimeImmutable('2026-07-02T10:00:00+00:00'),
            reason:      null,
            resolvedBy:  'approver-1',
            resolvedAt:  new DateTimeImmutable('2026-07-01T11:00:00+00:00'),
        );
    }
}
