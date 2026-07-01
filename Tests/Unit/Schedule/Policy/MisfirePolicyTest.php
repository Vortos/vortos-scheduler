<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Schedule\Policy;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Schedule\Policy\FireEachMissed;
use Vortos\Scheduler\Schedule\Policy\FireOnceNow;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\SkipMissed;

final class MisfirePolicyTest extends TestCase
{
    // ──────────────────────────────────────────────
    // Factory methods and key()
    // ──────────────────────────────────────────────

    public function test_skip_missed_key(): void
    {
        self::assertSame('skip_missed', MisfirePolicy::skipMissed()->key());
    }

    public function test_fire_once_now_key(): void
    {
        self::assertSame('fire_once_now', MisfirePolicy::fireOnceNow()->key());
    }

    public function test_fire_each_missed_key(): void
    {
        self::assertSame('fire_each_missed', MisfirePolicy::fireEachMissed()->key());
    }

    // ──────────────────────────────────────────────
    // Concrete types returned by factories
    // ──────────────────────────────────────────────

    public function test_skip_missed_factory_returns_skip_missed_instance(): void
    {
        self::assertInstanceOf(SkipMissed::class, MisfirePolicy::skipMissed());
    }

    public function test_fire_once_now_factory_returns_fire_once_now_instance(): void
    {
        self::assertInstanceOf(FireOnceNow::class, MisfirePolicy::fireOnceNow());
    }

    public function test_fire_each_missed_factory_returns_fire_each_missed_instance(): void
    {
        self::assertInstanceOf(FireEachMissed::class, MisfirePolicy::fireEachMissed());
    }

    // ──────────────────────────────────────────────
    // FireEachMissed cap
    // ──────────────────────────────────────────────

    public function test_fire_each_missed_default_cap_is_100(): void
    {
        $policy = MisfirePolicy::fireEachMissed();

        self::assertSame(100, $policy->cap);
    }

    public function test_fire_each_missed_cap_stored(): void
    {
        $policy = MisfirePolicy::fireEachMissed(500);

        self::assertSame(500, $policy->cap);
    }

    public function test_fire_each_missed_cap_of_one_accepted(): void
    {
        $policy = MisfirePolicy::fireEachMissed(1);

        self::assertSame(1, $policy->cap);
    }

    public function test_fire_each_missed_cap_of_1000_accepted(): void
    {
        $policy = MisfirePolicy::fireEachMissed(1000);

        self::assertSame(1000, $policy->cap);
    }

    public function test_fire_each_missed_cap_zero_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cap must be/i');

        MisfirePolicy::fireEachMissed(0);
    }

    public function test_fire_each_missed_cap_negative_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MisfirePolicy::fireEachMissed(-1);
    }

    public function test_fire_each_missed_cap_above_max_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MisfirePolicy::fireEachMissed(1001);
    }

    public function test_fire_each_missed_cap_far_above_max_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MisfirePolicy::fireEachMissed(PHP_INT_MAX);
    }

    // ──────────────────────────────────────────────
    // Immutability (readonly)
    // ──────────────────────────────────────────────

    public function test_skip_missed_is_readonly(): void
    {
        $policy = MisfirePolicy::skipMissed();

        $this->expectException(\Error::class);

        // @phpstan-ignore-next-line
        $policy->someProperty = 'mutation attempt';
    }

    public function test_fire_each_missed_is_readonly(): void
    {
        $policy = MisfirePolicy::fireEachMissed(50);

        $this->expectException(\Error::class);

        // @phpstan-ignore-next-line
        $policy->cap = 999;
    }

    // ──────────────────────────────────────────────
    // Constants
    // ──────────────────────────────────────────────

    public function test_fire_each_missed_constants(): void
    {
        self::assertSame(1, FireEachMissed::MIN_CAP);
        self::assertSame(1000, FireEachMissed::MAX_CAP);
        self::assertSame(100, FireEachMissed::DEFAULT_CAP);
    }
}
