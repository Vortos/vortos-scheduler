<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Clock;

use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Clock\ClockPort;

final class MutableClockTest extends TestCase
{
    public function test_implements_clock_port(): void
    {
        self::assertInstanceOf(ClockPort::class, new MutableClock(new DateTimeImmutable()));
    }

    public function test_now_returns_frozen_instant(): void
    {
        $t     = new DateTimeImmutable('2026-06-30T10:00:00Z');
        $clock = new MutableClock($t);

        self::assertEquals($t, $clock->now());
    }

    public function test_advance_seconds_moves_time_forward(): void
    {
        $start = new DateTimeImmutable('2026-06-30T10:00:00Z');
        $clock = new MutableClock($start);

        $clock->advanceSeconds(60);

        self::assertEquals(
            new DateTimeImmutable('2026-06-30T10:01:00Z'),
            $clock->now(),
        );
    }

    public function test_advance_minutes_moves_time(): void
    {
        $start = new DateTimeImmutable('2026-06-30T10:00:00Z');
        $clock = new MutableClock($start);

        $clock->advanceMinutes(5);

        self::assertEquals(
            new DateTimeImmutable('2026-06-30T10:05:00Z'),
            $clock->now(),
        );
    }

    public function test_advance_with_date_interval(): void
    {
        $start = new DateTimeImmutable('2026-06-30T10:00:00Z');
        $clock = new MutableClock($start);

        $clock->advance(new DateInterval('PT1H30M'));

        self::assertEquals(
            new DateTimeImmutable('2026-06-30T11:30:00Z'),
            $clock->now(),
        );
    }

    public function test_freeze_overwrites_advanced_time(): void
    {
        $start = new DateTimeImmutable('2026-06-30T10:00:00Z');
        $clock = new MutableClock($start);

        $clock->advanceSeconds(3600);
        $clock->freeze($start);

        self::assertEquals($start, $clock->now());
    }

    public function test_multiple_advances_are_cumulative(): void
    {
        $start = new DateTimeImmutable('2026-06-30T10:00:00Z');
        $clock = new MutableClock($start);

        $clock->advanceSeconds(30);
        $clock->advanceSeconds(30);

        self::assertEquals(
            new DateTimeImmutable('2026-06-30T10:01:00Z'),
            $clock->now(),
        );
    }

    public function test_now_is_immutable_snapshot(): void
    {
        $clock = new MutableClock(new DateTimeImmutable('2026-06-30T10:00:00Z'));
        $snap1 = $clock->now();

        $clock->advanceSeconds(10);
        $snap2 = $clock->now();

        // $snap1 must not change after the advance
        self::assertNotEquals($snap1, $snap2);
        self::assertEquals(new DateTimeImmutable('2026-06-30T10:00:00Z'), $snap1);
    }
}
