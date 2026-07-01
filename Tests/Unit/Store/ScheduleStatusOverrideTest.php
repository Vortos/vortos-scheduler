<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Store;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Store\ScheduleStatusOverride;

/**
 * @covers \Vortos\Scheduler\Store\ScheduleStatusOverride
 */
final class ScheduleStatusOverrideTest extends TestCase
{
    public function test_constructor_stores_all_fields(): void
    {
        $id    = ScheduleId::generate();
        $now   = new DateTimeImmutable('2026-07-01T10:00:00+00:00');
        $sut   = new ScheduleStatusOverride($id, ScheduleStatus::Paused, 'actor-1', 'maintenance', $now);

        self::assertSame($id, $sut->scheduleId);
        self::assertSame(ScheduleStatus::Paused, $sut->status);
        self::assertSame('actor-1', $sut->actorId);
        self::assertSame('maintenance', $sut->reason);
        self::assertSame($now, $sut->updatedAt);
    }

    public function test_null_reason_is_preserved(): void
    {
        $sut = new ScheduleStatusOverride(
            ScheduleId::generate(),
            ScheduleStatus::Active,
            'actor-2',
            null,
            new DateTimeImmutable(),
        );

        self::assertNull($sut->reason);
    }

    public function test_active_status_is_stored(): void
    {
        $sut = new ScheduleStatusOverride(
            ScheduleId::generate(),
            ScheduleStatus::Active,
            'actor-3',
            null,
            new DateTimeImmutable(),
        );

        self::assertSame(ScheduleStatus::Active, $sut->status);
    }

    public function test_paused_status_is_stored(): void
    {
        $sut = new ScheduleStatusOverride(
            ScheduleId::generate(),
            ScheduleStatus::Paused,
            'actor-4',
            'planned outage',
            new DateTimeImmutable(),
        );

        self::assertSame(ScheduleStatus::Paused, $sut->status);
    }

    public function test_is_readonly(): void
    {
        $sut = new ScheduleStatusOverride(
            ScheduleId::generate(),
            ScheduleStatus::Paused,
            'actor',
            null,
            new DateTimeImmutable(),
        );

        $refl = new \ReflectionClass($sut);
        self::assertTrue($refl->isReadOnly());
    }
}
