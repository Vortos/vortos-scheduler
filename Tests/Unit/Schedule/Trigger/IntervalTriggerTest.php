<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Schedule\Trigger;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;
use Vortos\Scheduler\Schedule\Trigger\Trigger;

final class IntervalTriggerTest extends TestCase
{
    public function test_implements_trigger(): void
    {
        self::assertInstanceOf(Trigger::class, new IntervalTrigger(60));
    }

    public function test_interval_below_minimum_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/minimum interval/i');

        new IntervalTrigger(0);
    }

    public function test_negative_interval_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IntervalTrigger(-1);
    }

    public function test_interval_of_one_second_accepted(): void
    {
        $trigger = new IntervalTrigger(1);

        self::assertInstanceOf(IntervalTrigger::class, $trigger);
    }

    public function test_next_run_is_exactly_interval_after_input(): void
    {
        $trigger = new IntervalTrigger(3600);
        $after   = new DateTimeImmutable('2026-06-30T10:00:00Z');

        $next = $trigger->nextRunAfter($after);

        self::assertEquals(
            new DateTimeImmutable('2026-06-30T11:00:00Z'),
            $next,
        );
    }

    public function test_one_second_interval(): void
    {
        $trigger = new IntervalTrigger(1);
        $after   = new DateTimeImmutable('2026-06-30T10:00:00Z');

        $next = $trigger->nextRunAfter($after);

        self::assertEquals(
            new DateTimeImmutable('2026-06-30T10:00:01Z'),
            $next,
        );
    }

    public function test_monotonicity(): void
    {
        $trigger = new IntervalTrigger(300);
        $t1      = new DateTimeImmutable('2026-06-30T10:00:00Z');
        $t2      = new DateTimeImmutable('2026-06-30T10:01:00Z');

        $next1 = $trigger->nextRunAfter($t1);
        $next2 = $trigger->nextRunAfter($t2);

        self::assertGreaterThanOrEqual($next1, $next2);
    }

    public function test_never_returns_null(): void
    {
        $trigger    = new IntervalTrigger(60);
        $farFuture  = new DateTimeImmutable('2099-01-01T00:00:00Z');

        $next = $trigger->nextRunAfter($farFuture);

        self::assertInstanceOf(DateTimeImmutable::class, $next);
    }

    public function test_large_interval(): void
    {
        $trigger = new IntervalTrigger(86400);
        $after   = new DateTimeImmutable('2026-06-30T00:00:00Z');

        $next = $trigger->nextRunAfter($after);

        self::assertEquals(
            new DateTimeImmutable('2026-07-01T00:00:00Z'),
            $next,
        );
    }

    public function test_describe_format(): void
    {
        $trigger = new IntervalTrigger(3600);

        self::assertSame('@every 3600s', $trigger->describe());
    }

    public function test_describe_with_one_second(): void
    {
        self::assertSame('@every 1s', (new IntervalTrigger(1))->describe());
    }

    public function test_advancing_after_is_stable(): void
    {
        $trigger = new IntervalTrigger(60);
        $t1      = new DateTimeImmutable('2026-06-30T10:00:00Z');
        $t2      = $t1->modify('+1 second');

        $diff = $trigger->nextRunAfter($t2)->getTimestamp() - $trigger->nextRunAfter($t1)->getTimestamp();

        self::assertSame(1, $diff, 'Advancing $after by 1s should advance the result by 1s');
    }
}
