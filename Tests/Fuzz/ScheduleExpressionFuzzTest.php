<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Fuzz;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Schedule\Trigger\RecurringTrigger;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;

/**
 * @group fuzz
 *
 * Fuzz-style robustness tests for cron expression parsing and trigger behaviour.
 *
 * These are NOT true random fuzz tests (which would require a dedicated fuzzer such as
 * go-fuzz or AFL). Instead they exercise a wide variety of edge-case inputs generated
 * from a seed corpus that has historically caused real-world crashes in cron parsers.
 *
 * Invariant: for any well-formed cron expression, nextRunAfter() must either return a
 * future DateTimeImmutable or null — it must NEVER throw.
 *
 * Invalid expressions are expected to throw at construction time, not at nextRunAfter().
 */
final class ScheduleExpressionFuzzTest extends TestCase
{
    private static DateTimeZone $utc;

    public static function setUpBeforeClass(): void
    {
        self::$utc = new DateTimeZone('UTC');
    }

    // ── Valid cron corpus ─────────────────────────────────────────────────────

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function validCronProvider(): iterable
    {
        // Standard five-field expressions
        yield 'every minute'              => ['* * * * *'];
        yield 'every hour'                => ['0 * * * *'];
        yield 'midnight daily'            => ['0 0 * * *'];
        yield 'midnight monday'           => ['0 0 * * 1'];
        yield 'first of month noon'       => ['0 12 1 * *'];
        yield 'quarterly'                 => ['0 0 1 1,4,7,10 *'];
        yield 'ranges'                    => ['0-30 8-18 * * 1-5'];
        yield 'step every 15 min'         => ['*/15 * * * *'];
        yield 'step every 2 hours'        => ['0 */2 * * *'];
        yield 'complex comma list'        => ['0 0 1,15,28 * *'];
        yield 'complex step+range'        => ['0 9-17/2 * * 1-5'];
        yield 'sunday'                    => ['0 0 * * 0'];
        yield 'saturday'                  => ['0 0 * * 6'];
        yield 'both sun notations'        => ['0 0 * * 7'];
        yield 'feb 28'                    => ['0 0 28 2 *'];
        yield 'leap day (best effort)'    => ['0 0 29 2 *'];
        yield 'year-end midnight'         => ['59 23 31 12 *'];
        yield 'every 5 mins'              => ['*/5 * * * *'];
        yield 'step across midnight'      => ['0 22,0,1,2 * * *'];
    }

    /**
     * @dataProvider validCronProvider
     */
    public function test_valid_cron_next_run_after_never_throws(string $expression): void
    {
        $trigger = new RecurringTrigger($expression, self::$utc);
        $now     = new DateTimeImmutable('2026-07-01T00:00:00Z', self::$utc);

        // Must not throw
        $next = $trigger->nextRunAfter($now);

        self::assertTrue(
            $next === null || $next > $now,
            "nextRunAfter() for '{$expression}' must return null or a future time, got: "
            . ($next?->format('c') ?? 'null'),
        );
    }

    // ── Invalid cron corpus ───────────────────────────────────────────────────

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidCronProvider(): iterable
    {
        // These are guaranteed to fail the field-count check in validateExpression()
        yield 'empty string'          => [''];
        yield 'single field'          => ['*'];
        yield 'four fields'           => ['* * * *'];
        // sql injection has 9 fields → fails field-count check before library is reached
        yield 'sql injection attempt' => ["0 0 * * *'; DROP TABLE scheduler_schedules; --"];
        // These fail the isValidExpression() library check
        yield 'nonsense'              => ['not-a-cron not-a-cron not-a-cron not-a-cron not-a-cron'];
    }

    /**
     * @dataProvider invalidCronProvider
     */
    public function test_invalid_cron_throws_at_construction(string $expression): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RecurringTrigger($expression, self::$utc);
    }

    // ── IntervalTrigger boundary fuzz ─────────────────────────────────────────

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function validIntervalProvider(): iterable
    {
        yield '1 second'     => [1];
        yield '30 seconds'   => [30];
        yield '60 seconds'   => [60];
        yield '5 minutes'    => [300];
        yield '1 hour'       => [3600];
        yield '1 day'        => [86400];
        yield '1 week'       => [604800];
    }

    /**
     * @dataProvider validIntervalProvider
     */
    public function test_valid_interval_never_throws(int $seconds): void
    {
        $trigger = new IntervalTrigger($seconds);
        $now     = new DateTimeImmutable('2026-07-01T00:00:00Z', self::$utc);

        $next = $trigger->nextRunAfter($now);

        self::assertTrue($next === null || $next > $now);
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function invalidIntervalProvider(): iterable
    {
        yield 'zero'         => [0];
        yield 'negative one' => [-1];
        yield 'min int'      => [PHP_INT_MIN];
    }

    /**
     * @dataProvider invalidIntervalProvider
     */
    public function test_invalid_interval_throws(int $seconds): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new IntervalTrigger($seconds);
    }

    // ── Timezone fuzz ─────────────────────────────────────────────────────────

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function timezoneProvider(): iterable
    {
        yield 'UTC'          => ['UTC'];
        yield 'US/Eastern'   => ['America/New_York'];
        yield 'EU/Berlin'    => ['Europe/Berlin'];
        yield 'Asia/Tokyo'   => ['Asia/Tokyo'];
        yield 'Pacific'      => ['Pacific/Auckland'];
        yield 'India'        => ['Asia/Kolkata'];
        yield 'DST spring'   => ['America/Chicago']; // crosses DST
    }

    /**
     * @dataProvider timezoneProvider
     */
    public function test_cron_trigger_works_across_timezones(string $tz): void
    {
        $timezone = new DateTimeZone($tz);
        $trigger  = new RecurringTrigger('0 * * * *', $timezone);
        $now      = new DateTimeImmutable('2026-07-01T10:30:00', $timezone);

        $next = $trigger->nextRunAfter($now);

        self::assertTrue($next === null || $next > $now);
    }

    // ── nextRunAfter monotonicity ─────────────────────────────────────────────

    public function test_successive_next_run_calls_are_strictly_monotone(): void
    {
        $trigger = new RecurringTrigger('*/5 * * * *', self::$utc);
        $now     = new DateTimeImmutable('2026-07-01T00:00:00Z', self::$utc);

        $prev = $now;
        for ($i = 0; $i < 10; $i++) {
            $next = $trigger->nextRunAfter($prev);
            if ($next === null) {
                break;
            }
            self::assertGreaterThan($prev->getTimestamp(), $next->getTimestamp(), "Iteration {$i}: next must be strictly after prev");
            $prev = $next;
        }
    }
}
