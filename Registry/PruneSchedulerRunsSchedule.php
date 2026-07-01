<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Registry;

use DateTimeZone;
use Vortos\Scheduler\Command\PruneSchedulerRunsCommand;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Schedule\Attribute\Scheduled;
use Vortos\Scheduler\Schedule\Policy\Jitter;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\RecurringTrigger;

/**
 * Framework-owned static schedule for auto-prune (S9 follow-up / auto-prune plan).
 *
 * Unlike normal static schedules (app-defined, discovered via `#[Scheduled]` +
 * autoconfiguration), this one is registered by SchedulerExtension itself,
 * unconditionally, gated on `runRetentionDays > 0` — see registerRetention() in
 * SchedulerExtension. It still carries `#[Scheduled]` and implements
 * StaticScheduleDefinition because StaticSchedulePass validates both regardless
 * of who added the compiler tag.
 *
 * Policy choices (SCHEDULER_AUTO_PRUNE_IMPL_PLAN.md item 8):
 *  - Daily at 03:00 UTC — low-traffic default, not env-configurable in v1.
 *  - 15-minute jitter — spreads load using the existing Jitter policy rather
 *    than inventing a second load-spreading mechanism.
 *  - OverlapPolicy::Skip — a batched delete can run close to the next day's
 *    fire window on a large first-run backlog; two concurrent sweeps racing
 *    the same rows is wasteful even though not unsafe (deletes are idempotent).
 *  - SkipMissed, explicitly declared — a missed prune fire is harmless
 *    (tomorrow's fire deletes the larger backlog), so catch-up firing buys
 *    nothing.
 *  - sensitive: false — pruning already-terminal, historical audit rows is not
 *    a 4-eyes-gated action.
 */
#[Scheduled]
final class PruneSchedulerRunsSchedule implements StaticScheduleDefinition
{
    /** Reserved system schedule ID — do not reuse for any other schedule. */
    public const SCHEDULE_ID = '00000000-0000-4000-8000-000000000042';
    public const SCHEDULE_NAME = 'scheduler-auto-prune';

    public static function build(): Schedule
    {
        return new Schedule(
            id:        ScheduleId::fromString(self::SCHEDULE_ID),
            name:      self::SCHEDULE_NAME,
            source:    ScheduleSource::Static,
            trigger:   new RecurringTrigger('0 3 * * *', new DateTimeZone('UTC')),
            command:   new CommandSpec(PruneSchedulerRunsCommand::class),
            misfire:   MisfirePolicy::skipMissed(),
            overlap:   OverlapPolicy::Skip,
            timezone:  new DateTimeZone('UTC'),
            jitter:    new Jitter(900),
            status:    ScheduleStatus::Active,
            tenantId:  null,
            sensitive: false,
            metadata:  ['misfire_policy_explicit' => 'true'],
        );
    }
}
