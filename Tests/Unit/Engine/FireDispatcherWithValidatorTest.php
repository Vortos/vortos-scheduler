<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Engine;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Engine\FireDispatcher;
use Vortos\Scheduler\Engine\FireDispatchResult;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Fire\ScheduledFire;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;
use Vortos\Scheduler\Security\CommandSpecValidator;
use Vortos\Scheduler\Security\Exception\CommandNotAllowlistedException;
use Vortos\Scheduler\Store\Exception\DuplicateSlotException;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;
use Vortos\Scheduler\Engine\SchedulerEnqueuerPort;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

final class FireDispatcherWithValidatorTest extends TestCase
{
    private ScheduleRunStoreInterface&MockObject $runStore;
    private SchedulerEnqueuerPort&MockObject $enqueuer;
    private Connection&MockObject $connection;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        $this->runStore   = $this->createMock(ScheduleRunStoreInterface::class);
        $this->enqueuer   = $this->createMock(SchedulerEnqueuerPort::class);
        $this->connection = $this->createMock(Connection::class);
        $this->clock      = new class implements ClockInterface {
            public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-07-01T10:00:00Z'); }
        };

        // Default: connection behaves like a successful transaction
        $this->connection->method('beginTransaction');
        $this->connection->method('commit');
        $this->connection->method('isTransactionActive')->willReturn(false);
    }

    public function test_dispatches_when_no_validator(): void
    {
        $this->runStore->method('insertRun');
        $this->enqueuer->method('enqueue');

        $dispatcher = $this->makeDispatcher();
        $schedule   = $this->makeSchedule('App\Command\AnyCommand');
        $fire       = $this->makeFire($schedule);

        $result = $dispatcher->dispatch($fire, $schedule);

        self::assertSame(FireDispatchResult::Dispatched, $result);
    }

    public function test_dispatches_when_command_is_allowlisted(): void
    {
        $this->runStore->method('insertRun');
        $this->enqueuer->method('enqueue');

        $validator  = new CommandSpecValidator(['App\Command\AllowedCommand' => true]);
        $dispatcher = $this->makeDispatcher(validator: $validator);
        $schedule   = $this->makeSchedule('App\Command\AllowedCommand');
        $fire       = $this->makeFire($schedule);

        $result = $dispatcher->dispatch($fire, $schedule);

        self::assertSame(FireDispatchResult::Dispatched, $result);
    }

    public function test_throws_when_command_is_not_allowlisted(): void
    {
        $validator  = new CommandSpecValidator(['App\Command\SafeCommand' => true]);
        $dispatcher = $this->makeDispatcher(validator: $validator);
        $schedule   = $this->makeSchedule('App\Command\DangerousCommand');
        $fire       = $this->makeFire($schedule);

        $this->connection->expects(self::never())->method('beginTransaction');

        $this->expectException(CommandNotAllowlistedException::class);
        $this->expectExceptionMessage('App\Command\DangerousCommand');
        $dispatcher->dispatch($fire, $schedule);
    }

    public function test_allowlist_check_runs_before_transaction(): void
    {
        $validator  = new CommandSpecValidator([]);
        $dispatcher = $this->makeDispatcher(validator: $validator);
        $schedule   = $this->makeSchedule('App\Command\AnyCommand');

        $this->connection->expects(self::never())->method('beginTransaction');

        $this->expectException(CommandNotAllowlistedException::class);
        $dispatcher->dispatch($this->makeFire($schedule), $schedule);
    }

    public function test_already_dispatched_when_duplicate_slot(): void
    {
        $this->runStore->method('insertRun')->willThrowException(
            new DuplicateSlotException('2026-07-01T09:00:00Z', ScheduleId::generate()),
        );
        $this->connection->method('rollBack');

        $dispatcher = $this->makeDispatcher();
        $schedule   = $this->makeSchedule('App\Command\Safe');
        $fire       = $this->makeFire($schedule);

        $result = $dispatcher->dispatch($fire, $schedule);

        self::assertSame(FireDispatchResult::AlreadyDispatched, $result);
    }

    public function test_null_validator_does_not_block_dispatch(): void
    {
        $this->runStore->method('insertRun');
        $this->enqueuer->method('enqueue');

        $dispatcher = $this->makeDispatcher(validator: null);
        $schedule   = $this->makeSchedule('App\Command\AnyCommand');

        $result = $dispatcher->dispatch($this->makeFire($schedule), $schedule);

        self::assertSame(FireDispatchResult::Dispatched, $result);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeDispatcher(?CommandSpecValidator $validator = null): FireDispatcher
    {
        return new FireDispatcher(
            runStore:          $this->runStore,
            enqueuer:          $this->enqueuer,
            connection:        $this->connection,
            clock:             $this->clock,
            assumedDoneTtlSec: 3600,
            validator:         $validator,
        );
    }

    private function makeSchedule(string $commandClass): Schedule
    {
        return new Schedule(
            id:       ScheduleId::generate(),
            name:     'test-schedule',
            source:   ScheduleSource::Dynamic,
            trigger:  new IntervalTrigger(3600),
            command:  new CommandSpec($commandClass),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::AllowConcurrent,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: 'tenant-1',
        );
    }

    private function makeFire(Schedule $schedule): ScheduledFire
    {
        return new ScheduledFire(
            scheduleId:   $schedule->id,
            tenantId:     'tenant-1',
            slot:         '2026-07-01T09:00:00Z',
            scheduledFor: new DateTimeImmutable('2026-07-01T09:00:00Z'),
        );
    }
}
