<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Service\Support;

use DateTimeZone;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Registry\StaticScheduleDefinition;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;

/** Sensitive static schedule for 4-eyes gate tests. */
final class SensitiveStaticScheduleDefinition implements StaticScheduleDefinition
{
    public const SCHEDULE_ID = '00000000-0000-4000-8000-000000000002';
    public const SCHEDULE_NAME = 'sensitive-static-schedule';

    public static function build(): Schedule
    {
        return new Schedule(
            id:        ScheduleId::fromString(self::SCHEDULE_ID),
            name:      self::SCHEDULE_NAME,
            source:    ScheduleSource::Static,
            trigger:   new IntervalTrigger(3600),
            command:   new CommandSpec('App\Command\CriticalCommand'),
            misfire:   MisfirePolicy::fireOnceNow(),
            overlap:   OverlapPolicy::Skip,
            timezone:  new DateTimeZone('UTC'),
            jitter:    null,
            status:    ScheduleStatus::Active,
            tenantId:  null,
            sensitive: true,
            metadata:  ['misfire_policy_explicit' => 'true'],
        );
    }
}
