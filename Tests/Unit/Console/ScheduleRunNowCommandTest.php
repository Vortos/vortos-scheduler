<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Console;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Console\ScheduleRunNowCommand;
use Vortos\Scheduler\Engine\FireDispatchResult;
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
 * @covers \Vortos\Scheduler\Console\ScheduleRunNowCommand
 */
final class ScheduleRunNowCommandTest extends TestCase
{
    private InMemoryScheduleStore $dynamicStore;
    private FakeFireDispatcherPort $dispatcher;
    private FakeSchedulePolicy $policy;

    protected function setUp(): void
    {
        $this->dynamicStore = new InMemoryScheduleStore();
        $this->dispatcher   = new FakeFireDispatcherPort();
        $this->policy       = new FakeSchedulePolicy();
    }

    private function makeService(): ScheduleService
    {
        return new ScheduleService(
            staticRegistry: new StaticScheduleRegistry([]),
            dynamicStore:   $this->dynamicStore,
            overrideStore:  new InMemoryScheduleStatusOverrideStore(),
            policy:         $this->policy,
            clock:          new MutableClock(new DateTimeImmutable('2026-07-01T12:00:00+00:00')),
            fireDispatcher: $this->dispatcher,
        );
    }

    private function seedSchedule(ScheduleStatus $status = ScheduleStatus::Active, ?string $tenantId = null): Schedule
    {
        $s = new Schedule(
            id:       ScheduleId::generate(),
            name:     'etl-job',
            source:   ScheduleSource::Dynamic,
            trigger:  new IntervalTrigger(3600),
            command:  new CommandSpec('App\Command\EtlCommand'),
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

    public function test_run_now_dispatched_exits_zero(): void
    {
        $schedule = $this->seedSchedule();
        $command  = new ScheduleRunNowCommand($this->makeService());
        $tester   = new CommandTester($command);

        $tester->execute([
            'id'      => $schedule->id->toString(),
            '--actor' => 'operator-1',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Dispatched', $tester->getDisplay());
    }

    public function test_run_now_skipped_overlap_exits_zero_with_message(): void
    {
        $schedule = $this->seedSchedule();
        $this->dispatcher->setResult(FireDispatchResult::SkippedOverlap);
        $command = new ScheduleRunNowCommand($this->makeService());
        $tester  = new CommandTester($command);

        $tester->execute([
            'id'      => $schedule->id->toString(),
            '--actor' => 'operator-1',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Skipped', $tester->getDisplay());
    }

    public function test_run_now_already_dispatched_exits_zero(): void
    {
        $schedule = $this->seedSchedule();
        $this->dispatcher->setResult(FireDispatchResult::AlreadyDispatched);
        $command = new ScheduleRunNowCommand($this->makeService());
        $tester  = new CommandTester($command);

        $tester->execute([
            'id'      => $schedule->id->toString(),
            '--actor' => 'operator-1',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Already dispatched', $tester->getDisplay());
    }

    public function test_run_now_with_unknown_id_exits_failure(): void
    {
        $command = new ScheduleRunNowCommand($this->makeService());
        $tester  = new CommandTester($command);

        $tester->execute([
            'id'      => ScheduleId::generate()->toString(),
            '--actor' => 'operator-1',
        ]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('not found', $tester->getDisplay());
    }

    public function test_run_now_disabled_schedule_exits_failure(): void
    {
        $schedule = $this->seedSchedule(ScheduleStatus::Disabled);
        $command  = new ScheduleRunNowCommand($this->makeService());
        $tester   = new CommandTester($command);

        $tester->execute([
            'id'      => $schedule->id->toString(),
            '--actor' => 'operator-1',
        ]);

        self::assertSame(1, $tester->getStatusCode());
    }

    public function test_run_now_with_reason_option(): void
    {
        $schedule = $this->seedSchedule();
        $command  = new ScheduleRunNowCommand($this->makeService());
        $tester   = new CommandTester($command);

        $tester->execute([
            'id'       => $schedule->id->toString(),
            '--actor'  => 'operator-1',
            '--reason' => 'manual trigger for debugging',
        ]);

        self::assertSame(0, $tester->getStatusCode());
    }

    public function test_run_now_with_tenant_option(): void
    {
        $schedule = $this->seedSchedule(tenantId: 'tenant-1');
        $command  = new ScheduleRunNowCommand($this->makeService());
        $tester   = new CommandTester($command);

        $tester->execute([
            'id'       => $schedule->id->toString(),
            '--actor'  => 'operator-1',
            '--tenant' => 'tenant-1',
        ]);

        self::assertSame(0, $tester->getStatusCode());
    }

    // ── R8-11 (B7): accept a schedule name, not just a UUID ───────────────────

    public function test_run_now_accepts_a_schedule_name(): void
    {
        $this->seedSchedule(); // name 'etl-job'
        $command = new ScheduleRunNowCommand($this->makeService(), $this->makeResolver());
        $tester  = new CommandTester($command);

        $tester->execute(['id' => 'etl-job', '--actor' => 'operator-1']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Dispatched', $tester->getDisplay());
    }

    public function test_run_now_unknown_name_is_a_friendly_error(): void
    {
        $command = new ScheduleRunNowCommand($this->makeService(), $this->makeResolver());
        $tester  = new CommandTester($command);

        $tester->execute(['id' => 'no-such-job', '--actor' => 'operator-1']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('No schedule named "no-such-job"', $tester->getDisplay());
    }

    public function test_run_now_uuid_still_works_with_resolver_present(): void
    {
        $schedule = $this->seedSchedule();
        $command  = new ScheduleRunNowCommand($this->makeService(), $this->makeResolver());
        $tester   = new CommandTester($command);

        $tester->execute(['id' => $schedule->id->toString(), '--actor' => 'operator-1']);

        self::assertSame(0, $tester->getStatusCode());
    }

    private function makeResolver(): \Vortos\Scheduler\Registry\ScheduleResolver
    {
        return new \Vortos\Scheduler\Registry\ScheduleResolver(
            new StaticScheduleRegistry([]),
            $this->dynamicStore,
        );
    }
}
