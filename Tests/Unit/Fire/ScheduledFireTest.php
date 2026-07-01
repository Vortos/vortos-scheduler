<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Fire;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Fire\ScheduledFire;
use Vortos\Scheduler\Schedule\ScheduleId;

final class ScheduledFireTest extends TestCase
{
    public function test_construction_stores_all_fields(): void
    {
        $scheduleId   = ScheduleId::generate();
        $slot         = $scheduleId->toString() . ':2026-07-01T10:00:00+00:00';
        $scheduledFor = new DateTimeImmutable('2026-07-01T10:00:00Z');

        $fire = new ScheduledFire(
            scheduleId:   $scheduleId,
            tenantId:     'tenant-a',
            slot:         $slot,
            scheduledFor: $scheduledFor,
            attempt:      2,
        );

        self::assertTrue($scheduleId->equals($fire->scheduleId));
        self::assertSame('tenant-a', $fire->tenantId);
        self::assertSame($slot, $fire->slot);
        self::assertSame($scheduledFor, $fire->scheduledFor);
        self::assertSame(2, $fire->attempt);
    }

    public function test_default_attempt_is_one(): void
    {
        $fire = new ScheduledFire(
            scheduleId:   ScheduleId::generate(),
            tenantId:     null,
            slot:         'any:2026-07-01T10:00:00+00:00',
            scheduledFor: new DateTimeImmutable(),
        );

        self::assertSame(1, $fire->attempt);
    }

    public function test_system_fire_has_null_tenant(): void
    {
        $fire = new ScheduledFire(
            scheduleId:   ScheduleId::generate(),
            tenantId:     null,
            slot:         'any:2026-07-01T10:00:00+00:00',
            scheduledFor: new DateTimeImmutable(),
        );

        self::assertNull($fire->tenantId);
    }
}
