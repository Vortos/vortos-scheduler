<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Security\Support;

use DateTimeZone;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;

final class ScheduleFactory
{
    public static function sensitive(?string $tenantId = 'tenant-1'): Schedule
    {
        return self::make(sensitive: true, tenantId: $tenantId);
    }

    public static function normal(?string $tenantId = 'tenant-1'): Schedule
    {
        return self::make(sensitive: false, tenantId: $tenantId);
    }

    public static function make(
        bool    $sensitive = false,
        ?string $tenantId  = 'tenant-1',
        string  $name      = 'test-schedule',
    ): Schedule {
        return new Schedule(
            id:        ScheduleId::generate(),
            name:      $name,
            source:    ScheduleSource::Dynamic,
            trigger:   new IntervalTrigger(3600),
            command:   new CommandSpec('App\Command\TestCommand'),
            misfire:   MisfirePolicy::skipMissed(),
            overlap:   OverlapPolicy::AllowConcurrent,
            timezone:  new DateTimeZone('UTC'),
            jitter:    null,
            status:    ScheduleStatus::Active,
            tenantId:  $tenantId,
            sensitive: $sensitive,
        );
    }
}
