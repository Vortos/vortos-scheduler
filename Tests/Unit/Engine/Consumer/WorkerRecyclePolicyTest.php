<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Engine\Consumer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Engine\Consumer\WorkerRecyclePolicy;

final class WorkerRecyclePolicyTest extends TestCase
{
    public function test_auto_threshold_is_a_fraction_of_the_ini_limit(): void
    {
        self::assertSame((int) floor(134217728 * 0.8), WorkerRecyclePolicy::fromOptions(0, 0, '128M', 0.0)->memoryLimitBytes);
    }

    public function test_auto_is_off_when_memory_is_unlimited(): void
    {
        self::assertNull(WorkerRecyclePolicy::fromOptions(0, 0, '-1', 0.0)->memoryLimitBytes);
    }

    public function test_explicit_threshold_below_the_ceiling_is_honoured(): void
    {
        self::assertSame(64 * 1048576, WorkerRecyclePolicy::fromOptions(64, 0, '256M', 0.0)->memoryLimitBytes);
    }

    public function test_explicit_threshold_at_or_above_the_ceiling_is_clamped_because_it_could_never_fire(): void
    {
        self::assertSame((int) floor(134217728 * 0.8), WorkerRecyclePolicy::fromOptions(128, 0, '128M', 0.0)->memoryLimitBytes);
        self::assertSame((int) floor(134217728 * 0.8), WorkerRecyclePolicy::fromOptions(512, 0, '128M', 0.0)->memoryLimitBytes);
    }

    public function test_negative_disables_the_memory_guard(): void
    {
        self::assertNull(WorkerRecyclePolicy::fromOptions(-1, 0, '128M', 0.0)->memoryLimitBytes);
    }

    public function test_stops_on_memory_only_once_the_threshold_is_reached(): void
    {
        $policy = new WorkerRecyclePolicy(100, null, 0.0);

        self::assertNull($policy->reasonToStop(99, 10.0));
        self::assertStringContainsString('memory', (string) $policy->reasonToStop(100, 10.0));
    }

    public function test_stops_on_time_only_once_the_limit_is_reached(): void
    {
        $policy = WorkerRecyclePolicy::fromOptions(-1, 3600, '-1', 1000.0);

        self::assertNull($policy->reasonToStop(0, 4599.0));
        self::assertStringContainsString('time limit', (string) $policy->reasonToStop(0, 4600.0));
    }

    public function test_zero_time_limit_never_stops(): void
    {
        self::assertNull(WorkerRecyclePolicy::fromOptions(-1, 0, '-1', 0.0)->reasonToStop(PHP_INT_MAX, 1e12));
    }

    /** @return iterable<string, array{string, ?int}> */
    public static function iniSizes(): iterable
    {
        yield 'megabytes'    => ['128M', 134217728];
        yield 'lowercase'    => ['256m', 268435456];
        yield 'gigabytes'    => ['1G', 1073741824];
        yield 'kilobytes'    => ['512K', 524288];
        yield 'plain bytes'  => ['268435456', 268435456];
        yield 'unlimited'    => ['-1', null];
        yield 'empty'        => ['', null];
        yield 'zero'         => ['0', null];
        yield 'unparseable'  => ['lots', null];
    }

    #[DataProvider('iniSizes')]
    public function test_parses_ini_sizes(string $ini, ?int $bytes): void
    {
        self::assertSame($bytes, WorkerRecyclePolicy::parseIniBytes($ini));
    }
}
