<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Command\PruneSchedulerRunsCommand;
use Vortos\Scheduler\Registry\PruneSchedulerRunsSchedule;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Policy\SkipMissed;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\RecurringTrigger;

final class PruneSchedulerRunsScheduleTest extends TestCase
{
    public function test_build_returns_valid_system_schedule(): void
    {
        $schedule = PruneSchedulerRunsSchedule::build();

        self::assertSame(PruneSchedulerRunsSchedule::SCHEDULE_NAME, $schedule->name);
        self::assertSame(PruneSchedulerRunsSchedule::SCHEDULE_ID, $schedule->id->toString());
        self::assertSame(ScheduleSource::Static, $schedule->source);
        self::assertNull($schedule->tenantId);
        self::assertFalse($schedule->sensitive);
        self::assertSame(ScheduleStatus::Active, $schedule->status);
    }

    public function test_dispatches_prune_scheduler_runs_command(): void
    {
        self::assertSame(PruneSchedulerRunsCommand::class, PruneSchedulerRunsSchedule::build()->command->commandClass);
    }

    public function test_uses_daily_cron_trigger(): void
    {
        $trigger = PruneSchedulerRunsSchedule::build()->trigger;

        self::assertInstanceOf(RecurringTrigger::class, $trigger);
        self::assertSame('0 3 * * *', $trigger->expression);
    }

    public function test_overlap_policy_is_skip(): void
    {
        self::assertSame(OverlapPolicy::Skip, PruneSchedulerRunsSchedule::build()->overlap);
    }

    public function test_misfire_policy_is_explicit_skip_missed(): void
    {
        $schedule = PruneSchedulerRunsSchedule::build();

        self::assertInstanceOf(SkipMissed::class, $schedule->misfire);
        self::assertSame('true', $schedule->metadata['misfire_policy_explicit'] ?? null);
    }

    public function test_jitter_window_is_fifteen_minutes(): void
    {
        $jitter = PruneSchedulerRunsSchedule::build()->jitter;

        self::assertNotNull($jitter);
        self::assertSame(900, $jitter->windowSeconds);
    }

    public function test_yields_future_next_run(): void
    {
        $schedule = PruneSchedulerRunsSchedule::build();
        $next     = $schedule->trigger->nextRunAfter(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        self::assertGreaterThan(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $next);
    }
}
