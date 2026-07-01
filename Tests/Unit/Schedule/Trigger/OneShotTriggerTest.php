<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Schedule\Trigger;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Schedule\Trigger\OneShotTrigger;
use Vortos\Scheduler\Schedule\Trigger\Trigger;

final class OneShotTriggerTest extends TestCase
{
    public function test_implements_trigger(): void
    {
        self::assertInstanceOf(
            Trigger::class,
            new OneShotTrigger(new DateTimeImmutable('2026-12-31T00:00:00Z')),
        );
    }

    public function test_fires_if_after_is_strictly_before_fire_at(): void
    {
        $fireAt  = new DateTimeImmutable('2026-12-31T00:00:00Z');
        $trigger = new OneShotTrigger($fireAt);
        $after   = new DateTimeImmutable('2026-12-30T23:59:59Z');

        $next = $trigger->nextRunAfter($after);

        self::assertNotNull($next);
        self::assertEquals($fireAt, $next);
    }

    public function test_returns_null_when_after_equals_fire_at(): void
    {
        $fireAt  = new DateTimeImmutable('2026-12-31T00:00:00Z');
        $trigger = new OneShotTrigger($fireAt);

        // Equality = slot already processed → null (strictly-after contract)
        $next = $trigger->nextRunAfter($fireAt);

        self::assertNull($next);
    }

    public function test_returns_null_when_after_is_past_fire_at(): void
    {
        $fireAt  = new DateTimeImmutable('2026-12-31T00:00:00Z');
        $trigger = new OneShotTrigger($fireAt);
        $after   = new DateTimeImmutable('2027-01-01T00:00:00Z');

        self::assertNull($trigger->nextRunAfter($after));
    }

    public function test_always_returns_null_for_far_future_after(): void
    {
        $fireAt  = new DateTimeImmutable('2020-01-01T00:00:00Z');
        $trigger = new OneShotTrigger($fireAt);

        self::assertNull($trigger->nextRunAfter(new DateTimeImmutable('2099-01-01T00:00:00Z')));
    }

    public function test_returns_fire_at_when_after_is_one_microsecond_before(): void
    {
        $fireAt  = new DateTimeImmutable('2026-12-31T00:00:00.000001Z');
        $trigger = new OneShotTrigger($fireAt);
        $after   = new DateTimeImmutable('2026-12-31T00:00:00.000000Z');

        $next = $trigger->nextRunAfter($after);

        self::assertNotNull($next);
        self::assertEquals($fireAt, $next);
    }

    public function test_describe_is_atom_format(): void
    {
        $fireAt  = new DateTimeImmutable('2026-12-31T00:00:00+00:00');
        $trigger = new OneShotTrigger($fireAt);

        self::assertStringStartsWith('@', $trigger->describe());
        // Should contain the ISO-8601 atom string
        self::assertStringContainsString('2026-12-31', $trigger->describe());
    }

    public function test_different_fire_at_values_produce_distinct_describe(): void
    {
        $a = new OneShotTrigger(new DateTimeImmutable('2026-06-01T00:00:00Z'));
        $b = new OneShotTrigger(new DateTimeImmutable('2026-07-01T00:00:00Z'));

        self::assertNotSame($a->describe(), $b->describe());
    }
}
