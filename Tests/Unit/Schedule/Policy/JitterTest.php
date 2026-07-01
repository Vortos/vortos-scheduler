<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Schedule\Policy;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Schedule\Policy\Jitter;

final class JitterTest extends TestCase
{
    public function test_window_below_minimum_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/window must be/i');

        new Jitter(0);
    }

    public function test_negative_window_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Jitter(-1);
    }

    public function test_window_above_maximum_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Jitter(3601);
    }

    public function test_window_of_one_accepted(): void
    {
        $jitter = new Jitter(1);

        self::assertSame(1, $jitter->windowSeconds);
    }

    public function test_window_of_3600_accepted(): void
    {
        $jitter = new Jitter(3600);

        self::assertSame(3600, $jitter->windowSeconds);
    }

    public function test_offset_is_in_range(): void
    {
        $jitter = new Jitter(60);
        $offset = $jitter->offsetSeconds('slot-key', 'node-1');

        self::assertGreaterThanOrEqual(0, $offset);
        self::assertLessThan(60, $offset);
    }

    public function test_offset_is_deterministic(): void
    {
        $jitter = new Jitter(300);
        $slot   = 'schedule-id:2026-06-30T02:00:00+10:00';
        $node   = 'node-abc';

        $a = $jitter->offsetSeconds($slot, $node);
        $b = $jitter->offsetSeconds($slot, $node);

        self::assertSame($a, $b);
    }

    public function test_different_slot_keys_may_produce_different_offsets(): void
    {
        $jitter  = new Jitter(3600);
        $slotA   = 'schedule-id:2026-06-30T02:00:00+10:00';
        $slotB   = 'schedule-id:2026-07-01T02:00:00+10:00';
        $node    = 'node-1';

        $offsetA = $jitter->offsetSeconds($slotA, $node);
        $offsetB = $jitter->offsetSeconds($slotB, $node);

        // They will almost certainly differ (distribution test, not a hard guarantee)
        // We assert range for both
        self::assertGreaterThanOrEqual(0, $offsetA);
        self::assertLessThan(3600, $offsetA);
        self::assertGreaterThanOrEqual(0, $offsetB);
        self::assertLessThan(3600, $offsetB);
    }

    public function test_different_node_ids_may_produce_different_offsets(): void
    {
        $jitter  = new Jitter(3600);
        $slot    = 'schedule-id:2026-06-30T02:00:00+10:00';

        $offsetA = $jitter->offsetSeconds($slot, 'node-1');
        $offsetB = $jitter->offsetSeconds($slot, 'node-2');

        // Both must be in range
        self::assertGreaterThanOrEqual(0, $offsetA);
        self::assertLessThan(3600, $offsetA);
        self::assertGreaterThanOrEqual(0, $offsetB);
        self::assertLessThan(3600, $offsetB);
    }

    public function test_offset_with_window_of_one_is_always_zero(): void
    {
        $jitter = new Jitter(1);

        // abs(anything) % 1 === 0
        self::assertSame(0, $jitter->offsetSeconds('any-slot', 'any-node'));
    }

    public function test_window_stored_correctly(): void
    {
        $jitter = new Jitter(120);

        self::assertSame(120, $jitter->windowSeconds);
    }

    public function test_jitter_is_readonly(): void
    {
        $jitter = new Jitter(60);

        $this->expectException(\Error::class);

        // @phpstan-ignore-next-line
        $jitter->windowSeconds = 999;
    }
}
