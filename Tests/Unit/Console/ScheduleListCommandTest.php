<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Console;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Scheduler\Console\ScheduleListCommand;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Registry\ScheduleResolver;
use Vortos\Scheduler\Registry\StaticScheduleRegistry;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;
use Vortos\Scheduler\Testing\InMemoryScheduleStatusOverrideStore;
use Vortos\Scheduler\Testing\InMemoryScheduleStore;

/**
 * @covers \Vortos\Scheduler\Console\ScheduleListCommand
 */
final class ScheduleListCommandTest extends TestCase
{
    private InMemoryScheduleStore $dynamicStore;
    private InMemoryScheduleStatusOverrideStore $overrideStore;

    protected function setUp(): void
    {
        $this->dynamicStore  = new InMemoryScheduleStore();
        $this->overrideStore = new InMemoryScheduleStatusOverrideStore();
    }

    private function makeResolver(?StaticScheduleRegistry $registry = null): ScheduleResolver
    {
        return new ScheduleResolver(
            $registry ?? new StaticScheduleRegistry([]),
            $this->dynamicStore,
            $this->overrideStore,
        );
    }

    private function seedSchedule(string $name = 'job-a', ?string $tenantId = null, ScheduleStatus $status = ScheduleStatus::Active): Schedule
    {
        $s = new Schedule(
            id:       ScheduleId::generate(),
            name:     $name,
            source:   ScheduleSource::Dynamic,
            trigger:  new IntervalTrigger(3600),
            command:  new CommandSpec('App\Command\TestCommand'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::AllowConcurrent,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   $status,
            tenantId: $tenantId,
        );
        $this->dynamicStore->seed($s);
        return $s;
    }

    public function test_list_empty_outputs_no_schedules_message(): void
    {
        $command = new ScheduleListCommand($this->makeResolver());
        $tester  = new CommandTester($command);
        $tester->execute([]);
        self::assertStringContainsString('No schedules', $tester->getDisplay());
        self::assertSame(0, $tester->getStatusCode());
    }

    public function test_list_shows_schedule_name(): void
    {
        $this->seedSchedule('invoice-generator');
        $command = new ScheduleListCommand($this->makeResolver());
        $tester  = new CommandTester($command);
        $tester->execute([]);
        self::assertStringContainsString('invoice-generator', $tester->getDisplay());
    }

    public function test_list_shows_count(): void
    {
        $this->seedSchedule('job-1');
        $this->seedSchedule('job-2');
        $command = new ScheduleListCommand($this->makeResolver());
        $tester  = new CommandTester($command);
        $tester->execute([]);
        self::assertStringContainsString('2 schedule', $tester->getDisplay());
    }

    public function test_list_json_output_is_valid_json(): void
    {
        $this->seedSchedule('export-job');
        $command = new ScheduleListCommand($this->makeResolver());
        $tester  = new CommandTester($command);
        $tester->execute(['--json' => true]);

        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertCount(1, $data);
        self::assertSame('export-job', $data[0]['name']);
    }

    public function test_list_json_includes_required_fields(): void
    {
        $this->seedSchedule('analytics-job');
        $command = new ScheduleListCommand($this->makeResolver());
        $tester  = new CommandTester($command);
        $tester->execute(['--json' => true]);

        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $row  = $data[0];
        self::assertArrayHasKey('id', $row);
        self::assertArrayHasKey('name', $row);
        self::assertArrayHasKey('source', $row);
        self::assertArrayHasKey('status', $row);
        self::assertArrayHasKey('trigger', $row);
        self::assertArrayHasKey('tenant', $row);
        self::assertArrayHasKey('next', $row);
    }

    public function test_list_status_filter_active(): void
    {
        $this->seedSchedule('active-job', status: ScheduleStatus::Active);
        $this->seedSchedule('paused-job', status: ScheduleStatus::Paused);
        $command = new ScheduleListCommand($this->makeResolver());
        $tester  = new CommandTester($command);
        $tester->execute(['--status' => 'active', '--json' => true]);

        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $data);
        self::assertSame('active-job', $data[0]['name']);
    }

    public function test_list_status_filter_all_returns_everything(): void
    {
        $this->seedSchedule('active-job', status: ScheduleStatus::Active);
        $this->seedSchedule('paused-job', status: ScheduleStatus::Paused);
        $command = new ScheduleListCommand($this->makeResolver());
        $tester  = new CommandTester($command);
        $tester->execute(['--status' => 'all', '--json' => true]);

        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(2, $data);
    }

    public function test_list_next_option_shows_next_fire_time(): void
    {
        $this->seedSchedule('hourly-job');
        $command = new ScheduleListCommand($this->makeResolver());
        $tester  = new CommandTester($command);
        $tester->execute(['--next' => 1, '--json' => true]);

        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($data[0]['next']);
    }

    public function test_list_next_is_capped_at_20(): void
    {
        $this->seedSchedule('capped-job');
        $command = new ScheduleListCommand($this->makeResolver());
        $tester  = new CommandTester($command);
        $tester->execute(['--next' => 9999, '--json' => true]);

        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertLessThanOrEqual(20, count($data[0]['next']));
    }

    public function test_list_exits_zero(): void
    {
        $command = new ScheduleListCommand($this->makeResolver());
        $tester  = new CommandTester($command);
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());
    }
}
