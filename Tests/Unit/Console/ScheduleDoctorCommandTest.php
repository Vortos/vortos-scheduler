<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Console;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Console\ScheduleDoctorCommand;
use Vortos\Scheduler\Doctor\SchedulerDoctor;
use Vortos\Scheduler\Lease\Driver\InMemoryLeaseStore;
use Vortos\Scheduler\Registry\ScheduleResolver;
use Vortos\Scheduler\Registry\StaticScheduleRegistry;
use Vortos\Scheduler\Testing\InMemoryScheduleStatusOverrideStore;
use Vortos\Scheduler\Testing\InMemoryScheduleStore;

/**
 * @covers \Vortos\Scheduler\Console\ScheduleDoctorCommand
 */
final class ScheduleDoctorCommandTest extends TestCase
{
    private function makeDoctor(array $tables = []): SchedulerDoctor
    {
        $clock      = new MutableClock(new DateTimeImmutable('2026-07-01T12:00:00+00:00'));
        $dynamicStore = new InMemoryScheduleStore();
        $conn       = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach ($tables as $table) {
            $conn->executeStatement("CREATE TABLE {$table} (id INTEGER PRIMARY KEY)");
        }

        $registry  = new StaticScheduleRegistry([]);
        $overrides = new InMemoryScheduleStatusOverrideStore();
        $resolver  = new ScheduleResolver($registry, $dynamicStore, $overrides);

        return new SchedulerDoctor(
            resolver:         $resolver,
            dynamicStore:     $dynamicStore,
            leasePort:        new InMemoryLeaseStore($clock),
            connection:       $conn,
            clock:            $clock,
            validator:        null,
            approvalStore:    null,
            tablePrefix:      'vortos_',
            shardCount:       1,
            maxCatchupAgeSec: 86400,
        );
    }

    public function test_doctor_outputs_human_table_by_default(): void
    {
        $command = new ScheduleDoctorCommand($this->makeDoctor());
        $tester  = new CommandTester($command);
        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('C1', $display);
        self::assertStringContainsString('C9', $display);
        self::assertStringContainsString('PASS', $display);
    }

    public function test_doctor_json_output_is_valid_json(): void
    {
        $command = new ScheduleDoctorCommand($this->makeDoctor());
        $tester  = new CommandTester($command);
        $tester->execute(['--json' => true]);

        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('schema_version', $data);
        self::assertArrayHasKey('clear', $data);
        self::assertArrayHasKey('findings', $data);
        self::assertCount(12, $data['findings']);
    }

    public function test_doctor_exits_one_when_checks_fail(): void
    {
        // No tables = C5 fails
        $command = new ScheduleDoctorCommand($this->makeDoctor([]));
        $tester  = new CommandTester($command);
        $tester->execute([]);
        self::assertSame(1, $tester->getStatusCode());
    }

    public function test_doctor_exits_zero_when_all_pass(): void
    {
        $tables = [
            'vortos_scheduler_schedules',
            'vortos_scheduler_runs',
            'vortos_scheduler_audit_log',
            'vortos_scheduler_audit_checkpoints',
            'vortos_scheduler_static_overrides',
            'vortos_scheduler_fire_queue',
            'vortos_scheduler_run_retention_overrides',
        ];
        $command = new ScheduleDoctorCommand($this->makeDoctor($tables));
        $tester  = new CommandTester($command);
        $tester->execute([]);
        // All checks pass (C3 and C6 skip, which is not a failure)
        self::assertSame(0, $tester->getStatusCode());
    }

    public function test_doctor_human_output_shows_pass_fail_skip_counts(): void
    {
        $command = new ScheduleDoctorCommand($this->makeDoctor());
        $tester  = new CommandTester($command);
        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Passed:', $display);
        self::assertStringContainsString('Failed:', $display);
        self::assertStringContainsString('Skipped:', $display);
    }

    public function test_doctor_shows_failure_message_when_checks_fail(): void
    {
        $command = new ScheduleDoctorCommand($this->makeDoctor([]));
        $tester  = new CommandTester($command);
        $tester->execute([]);
        self::assertStringContainsString('failed', strtolower($tester->getDisplay()));
    }

    public function test_doctor_shows_healthy_message_when_all_pass(): void
    {
        $tables = [
            'vortos_scheduler_schedules',
            'vortos_scheduler_runs',
            'vortos_scheduler_audit_log',
            'vortos_scheduler_audit_checkpoints',
            'vortos_scheduler_static_overrides',
            'vortos_scheduler_fire_queue',
            'vortos_scheduler_run_retention_overrides',
        ];
        $command = new ScheduleDoctorCommand($this->makeDoctor($tables));
        $tester  = new CommandTester($command);
        $tester->execute([]);
        self::assertStringContainsString('healthy', strtolower($tester->getDisplay()));
    }
}
