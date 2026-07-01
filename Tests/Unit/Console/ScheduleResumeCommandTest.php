<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Console;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Console\ScheduleResumeCommand;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Registry\StaticScheduleRegistry;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;
use Vortos\Scheduler\Service\ScheduleService;
use Vortos\Scheduler\Testing\FakeFireDispatcherPort;
use Vortos\Scheduler\Testing\FakeSchedulePolicy;
use Vortos\Scheduler\Testing\InMemoryScheduleStatusOverrideStore;
use Vortos\Scheduler\Testing\InMemoryScheduleStore;

/**
 * @covers \Vortos\Scheduler\Console\ScheduleResumeCommand
 */
final class ScheduleResumeCommandTest extends TestCase
{
    private InMemoryScheduleStore $dynamicStore;
    private InMemoryScheduleStatusOverrideStore $overrideStore;
    private FakeSchedulePolicy $policy;

    protected function setUp(): void
    {
        $this->dynamicStore  = new InMemoryScheduleStore();
        $this->overrideStore = new InMemoryScheduleStatusOverrideStore();
        $this->policy        = new FakeSchedulePolicy();
    }

    private function makeService(): ScheduleService
    {
        return new ScheduleService(
            staticRegistry: new StaticScheduleRegistry([]),
            dynamicStore:   $this->dynamicStore,
            overrideStore:  $this->overrideStore,
            policy:         $this->policy,
            clock:          new MutableClock(new DateTimeImmutable('2026-07-01T12:00:00+00:00')),
            fireDispatcher: new FakeFireDispatcherPort(),
        );
    }

    private function seedSchedule(ScheduleStatus $status = ScheduleStatus::Paused, ?string $tenantId = null): Schedule
    {
        $s = new Schedule(
            id:       ScheduleId::generate(),
            name:     'report-job',
            source:   ScheduleSource::Dynamic,
            trigger:  new IntervalTrigger(3600),
            command:  new CommandSpec('App\Command\ReportCommand'),
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

    public function test_resume_succeeds_and_exits_zero(): void
    {
        $schedule = $this->seedSchedule();
        $command  = new ScheduleResumeCommand($this->makeService());
        $tester   = new CommandTester($command);

        $tester->execute([
            'id'      => $schedule->id->toString(),
            '--actor' => 'operator-1',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('resumed successfully', $tester->getDisplay());
    }

    public function test_resume_outputs_schedule_name(): void
    {
        $schedule = $this->seedSchedule();
        $command  = new ScheduleResumeCommand($this->makeService());
        $tester   = new CommandTester($command);

        $tester->execute([
            'id'      => $schedule->id->toString(),
            '--actor' => 'operator-1',
        ]);

        self::assertStringContainsString('report-job', $tester->getDisplay());
    }

    public function test_resume_with_unknown_id_exits_failure(): void
    {
        $command = new ScheduleResumeCommand($this->makeService());
        $tester  = new CommandTester($command);

        $tester->execute([
            'id'      => ScheduleId::generate()->toString(),
            '--actor' => 'operator-1',
        ]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('not found', $tester->getDisplay());
    }

    public function test_resume_access_denied_exits_failure(): void
    {
        $schedule = $this->seedSchedule();
        $this->policy->deny();

        $command = new ScheduleResumeCommand($this->makeService());
        $tester  = new CommandTester($command);

        $tester->execute([
            'id'      => $schedule->id->toString(),
            '--actor' => 'operator-1',
        ]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Access denied', $tester->getDisplay());
    }
}
