<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Schedule\Trigger;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Schedule\Trigger\CronDialect;
use Vortos\Scheduler\Schedule\Trigger\RecurringTrigger;

/**
 * Tests for RecurringTrigger — standard cases and the two mandated DST scenarios.
 *
 * DST reference dates for Australia/Sydney:
 *   Spring-forward (AEST→AEDT): 2026-10-04 02:00 AEST → 03:00 AEDT (UTC+10 → UTC+11)
 *   Fall-back      (AEDT→AEST): 2026-04-05 03:00 AEDT → 02:00 AEST (UTC+11 → UTC+10)
 */
final class RecurringTriggerTest extends TestCase
{
    // ──────────────────────────────────────────────
    // Construction / validation
    // ──────────────────────────────────────────────

    public function test_invalid_cron_expression_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RecurringTrigger('not a cron', new DateTimeZone('UTC'));
    }

    public function test_six_field_expression_with_five_field_dialect_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // 6 fields but FiveField dialect
        new RecurringTrigger('0 30 2 * * *', new DateTimeZone('UTC'), CronDialect::FiveField);
    }

    public function test_five_field_expression_with_six_field_dialect_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // 5 fields but SixFieldSeconds dialect
        new RecurringTrigger('30 2 * * *', new DateTimeZone('UTC'), CronDialect::SixFieldSeconds);
    }

    public function test_valid_five_field_expression_accepted(): void
    {
        $trigger = new RecurringTrigger('* * * * *', new DateTimeZone('UTC'));

        self::assertStringContainsString('* * * * *', $trigger->describe());
    }

    public function test_valid_six_field_seconds_expression_accepted(): void
    {
        $trigger = new RecurringTrigger('0 30 2 * * *', new DateTimeZone('UTC'), CronDialect::SixFieldSeconds);

        self::assertStringContainsString('six_field_seconds', $trigger->describe());
    }

    // ──────────────────────────────────────────────
    // Standard scheduling behaviour
    // ──────────────────────────────────────────────

    public function test_next_run_is_strictly_after_input(): void
    {
        $trigger = new RecurringTrigger('* * * * *', new DateTimeZone('UTC'));
        $after   = new DateTimeImmutable('2026-06-30T10:00:00Z');

        $next = $trigger->nextRunAfter($after);

        self::assertGreaterThan($after, $next);
    }

    public function test_next_minute_cron(): void
    {
        $trigger = new RecurringTrigger('* * * * *', new DateTimeZone('UTC'));
        $after   = new DateTimeImmutable('2026-06-30T10:00:30Z');

        $next = $trigger->nextRunAfter($after);

        self::assertEquals(
            new DateTimeImmutable('2026-06-30T10:01:00Z'),
            $next->setTimezone(new DateTimeZone('UTC')),
        );
    }

    public function test_hourly_cron_jumps_to_next_hour(): void
    {
        $trigger = new RecurringTrigger('0 * * * *', new DateTimeZone('UTC'));
        $after   = new DateTimeImmutable('2026-06-30T10:01:00Z');

        $next = $trigger->nextRunAfter($after);

        self::assertEquals(
            new DateTimeImmutable('2026-06-30T11:00:00Z'),
            $next->setTimezone(new DateTimeZone('UTC')),
        );
    }

    public function test_daily_2am_cron(): void
    {
        $trigger = new RecurringTrigger('0 2 * * *', new DateTimeZone('UTC'));
        $after   = new DateTimeImmutable('2026-06-30T03:00:00Z');

        $next = $trigger->nextRunAfter($after);

        self::assertEquals(
            new DateTimeImmutable('2026-07-01T02:00:00Z'),
            $next->setTimezone(new DateTimeZone('UTC')),
        );
    }

    public function test_monotonicity(): void
    {
        $trigger = new RecurringTrigger('0 * * * *', new DateTimeZone('UTC'));
        $t1      = new DateTimeImmutable('2026-06-30T10:00:00Z');
        $t2      = new DateTimeImmutable('2026-06-30T10:30:00Z');

        $next1 = $trigger->nextRunAfter($t1);
        $next2 = $trigger->nextRunAfter($t2);

        self::assertGreaterThanOrEqual($next1, $next2);
    }

    public function test_describe_includes_expression_and_timezone(): void
    {
        $trigger = new RecurringTrigger('0 2 * * *', new DateTimeZone('Australia/Sydney'));

        $desc = $trigger->describe();

        self::assertStringContainsString('0 2 * * *', $desc);
        self::assertStringContainsString('Australia/Sydney', $desc);
    }

    // ──────────────────────────────────────────────
    // DST — Australia/Sydney spring-forward (2026-10-04)
    // ──────────────────────────────────────────────

    /**
     * Spring-forward: 2026-10-04 02:00 AEST → 03:00 AEDT.
     * The window 02:00–03:00 local time does not exist on that day.
     * A daily "30 2 * * *" schedule must NOT return a non-existent 02:30.
     */
    public function test_dst_spring_forward_skips_to_valid_time(): void
    {
        $tz      = new DateTimeZone('Australia/Sydney');
        $trigger = new RecurringTrigger('30 2 * * *', $tz);

        // $after = just before midnight on Oct 3 in Sydney (Oct 3 23:59 local = Oct 3 12:59 UTC)
        // This means the next due slot should be Oct 4, but Oct 4 02:30 doesn't exist.
        $after = new DateTimeImmutable('2026-10-03T12:59:00Z');

        $next = $trigger->nextRunAfter($after);

        // Must be a valid DateTimeImmutable (library must not crash on non-existent time)
        self::assertInstanceOf(DateTimeImmutable::class, $next);

        // Must be strictly after the input
        self::assertGreaterThan($after, $next);

        // Must be a valid datetime — PHP's TZ engine resolves non-existent instants
        // to the nearest valid time; verify the timestamp is a real UTC time.
        self::assertNotFalse($next->getTimestamp());

        // The local time must NOT be in the non-existent gap (02:00–02:59 on 2026-10-04 in Sydney).
        // Convert to Sydney TZ and assert the time is NOT within the skipped window.
        $inSydney = $next->setTimezone($tz);
        $date     = $inSydney->format('Y-m-d');
        $hour     = (int) $inSydney->format('G');

        if ($date === '2026-10-04') {
            // On spring-forward day: 02:xx cannot be a valid local time
            self::assertNotEquals(
                2,
                $hour,
                'Spring-forward: 02:xx does not exist on 2026-10-04 in Australia/Sydney; got: ' . $inSydney->format('c'),
            );
        }
    }

    public function test_dst_spring_forward_result_is_after_input(): void
    {
        $tz      = new DateTimeZone('Australia/Sydney');
        $trigger = new RecurringTrigger('30 2 * * *', $tz);
        $after   = new DateTimeImmutable('2026-10-03T12:59:00Z');

        $next = $trigger->nextRunAfter($after);

        self::assertGreaterThan($after, $next, 'nextRunAfter must be strictly after $after');
    }

    // ──────────────────────────────────────────────
    // DST — Australia/Sydney fall-back (2026-04-05)
    // ──────────────────────────────────────────────

    /**
     * Fall-back: 2026-04-05 03:00 AEDT → 02:00 AEST.
     * 02:30 local exists twice: once at AEDT (+11:00) and once at AEST (+10:00).
     *
     * dragonmantank correctly returns the AEST occurrence after the AEDT one — both are
     * legitimate scheduled slots on the same calendar day.  The DOUBLE-FIRE guard is at
     * the idempotency-key layer: the slot key embeds the UTC offset, so
     *   scheduleId:2026-04-05T02:30:00+11:00  and
     *   scheduleId:2026-04-05T02:30:00+10:00
     * are two distinct keys.  If two daemon nodes race on the same slot they get the same
     * key and only one wins — not tested here (that is SlotCalculator's job).
     */
    public function test_dst_fall_back_second_occurrence_is_aest_same_day(): void
    {
        $tz      = new DateTimeZone('Australia/Sydney');
        $trigger = new RecurringTrigger('30 2 * * *', $tz);

        // First fire: 02:30 AEDT on 2026-04-05 = 2026-04-04T15:30:00Z
        $firstFire = new DateTimeImmutable('2026-04-04T15:30:00Z');

        $next = $trigger->nextRunAfter($firstFire);

        self::assertInstanceOf(DateTimeImmutable::class, $next);
        self::assertGreaterThan($firstFire, $next);

        $inSydney = $next->setTimezone($tz);

        // The library returns the AEST occurrence — same calendar day, different UTC offset.
        self::assertSame('2026-04-05', $inSydney->format('Y-m-d'), 'Should be same fall-back day (AEST occurrence)');
        self::assertSame('02:30', $inSydney->format('H:i'), 'Local wall-clock must still be 02:30');
        self::assertSame('+10:00', $inSydney->format('P'), 'Must be AEST (UTC+10), not AEDT (UTC+11)');
    }

    /**
     * Verify slot key encoding: the UTC offset IS part of the ISO-8601 string,
     * so AEDT (+11:00) and AEST (+10:00) at the same wall-clock time produce
     * different slot-key material. This is a SlotCalculator concern, but verifying
     * DateTimeImmutable::format('c') embeds the offset is meaningful here too.
     */
    public function test_dst_fall_back_different_offsets_produce_different_iso_strings(): void
    {
        $tz = new DateTimeZone('Australia/Sydney');

        // 02:30 AEDT (UTC+11) = 2026-04-04T15:30:00Z
        $aedt = (new DateTimeImmutable('2026-04-04T15:30:00Z'))->setTimezone($tz);

        // 02:30 AEST (UTC+10) = 2026-04-04T16:30:00Z
        $aest = (new DateTimeImmutable('2026-04-04T16:30:00Z'))->setTimezone($tz);

        // Both show 02:30 local time but differ in UTC offset
        self::assertSame('02:30', $aedt->format('H:i'));
        self::assertSame('02:30', $aest->format('H:i'));

        // Their ISO-8601 strings must differ (offset embedded)
        self::assertNotSame($aedt->format('c'), $aest->format('c'));
    }
}
