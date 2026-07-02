<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Engine;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Engine\MisfireResolver;
use Vortos\Scheduler\Engine\SlotCalculator;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;

/**
 * Unit tests for MisfireResolver (pure, no I/O).
 *
 * The resolver takes a typed cadence cursor (a DateTimeImmutable) — never a slot-key string — and
 * returns fires, dropped slots, AND the advanced cursor to persist. Uses IntervalTrigger(3600) =
 * every-hour trigger for deterministic slot sequences.
 */
final class MisfireResolverTest extends TestCase
{
    private MisfireResolver $resolver;
    private SlotCalculator  $slotCalc;
    private DateTimeZone    $utcTz;

    protected function setUp(): void
    {
        $this->slotCalc = new SlotCalculator();
        $this->resolver = new MisfireResolver($this->slotCalc);
        $this->utcTz    = new DateTimeZone('UTC');
    }

    // ─────────────────────────────────────────────────────────────
    // SkipMissed policy
    // ─────────────────────────────────────────────────────────────

    public function test_skip_missed_single_due_slot_fires(): void
    {
        $schedule = $this->makeSchedule(MisfirePolicy::skipMissed());
        $now      = new DateTimeImmutable('2026-07-01T11:00:01Z');
        $cursor   = new DateTimeImmutable('2026-07-01T10:00:00Z'); // one slot due (11:00)

        $result = $this->resolver->resolve($schedule, $cursor, $now);

        self::assertCount(1, $result->fires);
        self::assertCount(0, $result->dropped);
        self::assertEquals($now, $result->newCursor);
    }

    public function test_skip_missed_multiple_due_slots_fires_nothing_but_advances_cursor(): void
    {
        // Regression: SkipMissed must advance the cursor even when it skips, or the anchor never
        // moves and the schedule deadlocks forever (Bug C).
        $schedule = $this->makeSchedule(MisfirePolicy::skipMissed());
        $now      = new DateTimeImmutable('2026-07-01T13:00:01Z');
        $cursor   = new DateTimeImmutable('2026-07-01T10:00:00Z'); // slots 11, 12, 13 due

        $result = $this->resolver->resolve($schedule, $cursor, $now);

        self::assertCount(0, $result->fires, 'SkipMissed should skip all when multiple slots due');
        self::assertEquals($now, $result->newCursor, 'cursor must advance to now despite skipping');
    }

    public function test_skip_missed_no_due_slots_advances_cursor_to_now(): void
    {
        $schedule = $this->makeSchedule(MisfirePolicy::skipMissed());
        $now      = new DateTimeImmutable('2026-07-01T10:30:00Z');
        $cursor   = new DateTimeImmutable('2026-07-01T10:00:00Z'); // nothing due until 11:00

        $result = $this->resolver->resolve($schedule, $cursor, $now);

        self::assertCount(0, $result->fires);
        self::assertCount(0, $result->dropped);
        self::assertEquals($now, $result->newCursor);
    }

    // ─────────────────────────────────────────────────────────────
    // FireOnceNow policy
    // ─────────────────────────────────────────────────────────────

    public function test_fire_once_now_fires_most_recent_slot_only(): void
    {
        $schedule = $this->makeSchedule(MisfirePolicy::fireOnceNow());
        $now      = new DateTimeImmutable('2026-07-01T14:00:01Z');
        $cursor   = new DateTimeImmutable('2026-07-01T10:00:00Z'); // slots 11, 12, 13, 14 due

        $result = $this->resolver->resolve($schedule, $cursor, $now);

        self::assertCount(1, $result->fires);
        $expectedSlot = $this->slotKey($schedule, new DateTimeImmutable('2026-07-01T14:00:00Z'));
        self::assertSame($expectedSlot, $result->fires[0]->slot);
        self::assertEquals($now, $result->newCursor);
    }

    public function test_fire_once_now_single_due_slot_fires_it(): void
    {
        $schedule = $this->makeSchedule(MisfirePolicy::fireOnceNow());
        $now      = new DateTimeImmutable('2026-07-01T11:00:01Z');
        $cursor   = new DateTimeImmutable('2026-07-01T10:00:00Z');

        $result = $this->resolver->resolve($schedule, $cursor, $now);

        self::assertCount(1, $result->fires);
    }

    // ─────────────────────────────────────────────────────────────
    // FireEachMissed policy
    // ─────────────────────────────────────────────────────────────

    public function test_fire_each_missed_fires_up_to_cap(): void
    {
        $schedule = $this->makeSchedule(MisfirePolicy::fireEachMissed(cap: 2));
        $now      = new DateTimeImmutable('2026-07-01T15:00:01Z');
        $cursor   = new DateTimeImmutable('2026-07-01T10:00:00Z'); // slots 11..15 due

        $result = $this->resolver->resolve($schedule, $cursor, $now);

        self::assertCount(2, $result->fires);
    }

    public function test_fire_each_missed_truncated_batch_advances_cursor_only_to_last_fired(): void
    {
        // Gradual-drain semantics: the cursor stops at the last FIRED slot so the remaining backlog
        // drains next tick instead of being abandoned.
        $schedule = $this->makeSchedule(MisfirePolicy::fireEachMissed(cap: 2));
        $now      = new DateTimeImmutable('2026-07-01T15:00:01Z');
        $cursor   = new DateTimeImmutable('2026-07-01T10:00:00Z'); // slots 11..15 due, cap 2

        $result = $this->resolver->resolve($schedule, $cursor, $now);

        self::assertCount(2, $result->fires);
        // Fired 11:00 and 12:00 → cursor stops at 12:00, not now.
        self::assertEquals(new DateTimeImmutable('2026-07-01T12:00:00Z'), $result->newCursor);
    }

    public function test_fire_each_missed_fires_all_when_under_cap_advances_to_now(): void
    {
        $schedule = $this->makeSchedule(MisfirePolicy::fireEachMissed(cap: 10));
        $now      = new DateTimeImmutable('2026-07-01T13:00:01Z');
        $cursor   = new DateTimeImmutable('2026-07-01T10:00:00Z'); // slots 11, 12, 13 due

        $result = $this->resolver->resolve($schedule, $cursor, $now);

        self::assertCount(3, $result->fires);
        self::assertEquals($now, $result->newCursor, 'all candidates fired → advance to now');
    }

    public function test_fire_each_missed_fires_oldest_first(): void
    {
        $schedule = $this->makeSchedule(MisfirePolicy::fireEachMissed(cap: 3));
        $now      = new DateTimeImmutable('2026-07-01T13:00:01Z');
        $cursor   = new DateTimeImmutable('2026-07-01T10:00:00Z');

        $result = $this->resolver->resolve($schedule, $cursor, $now);

        self::assertCount(3, $result->fires);
        self::assertLessThan(
            $result->fires[1]->scheduledFor->getTimestamp(),
            $result->fires[0]->scheduledFor->getTimestamp(),
            'Fires must be oldest-first',
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Horizon dropping (catch-up cap)
    // ─────────────────────────────────────────────────────────────

    public function test_slots_beyond_horizon_are_dropped_not_fired(): void
    {
        $schedule      = $this->makeSchedule(MisfirePolicy::fireEachMissed(cap: 1000));
        $maxCatchupAge = 7200; // 2-hour window
        $now           = new DateTimeImmutable('2026-07-01T15:00:01Z');
        $cursor        = new DateTimeImmutable('2026-07-01T10:00:00Z'); // 5h behind

        $result = $this->resolver->resolve($schedule, $cursor, $now, $maxCatchupAge);

        // Slots 11, 12, 13 are beyond horizon (>2h before now); 14, 15 are within.
        self::assertCount(2, $result->fires);
        self::assertCount(1, $result->dropped, 'One batch DroppedSlotRecord expected for beyond-horizon period');
        self::assertEquals($now, $result->newCursor);
    }

    // ─────────────────────────────────────────────────────────────
    // Fresh schedule (never scanned → anchored to now by the daemon)
    // ─────────────────────────────────────────────────────────────

    public function test_fresh_schedule_anchored_to_now_does_no_catchup(): void
    {
        // Bug A/B: a never-scanned schedule is anchored to `now`, NOT the 24h horizon, so it does
        // not enumerate a day of "missed" slots. With cursor == now there is nothing due yet.
        $schedule = $this->makeSchedule(MisfirePolicy::skipMissed());
        $now      = new DateTimeImmutable('2026-07-01T10:00:30Z');

        $result = $this->resolver->resolve($schedule, $now, $now);

        self::assertCount(0, $result->fires);
        self::assertCount(0, $result->dropped);
        self::assertEquals($now, $result->newCursor);
    }

    public function test_fresh_schedule_fires_first_boundary_on_next_tick(): void
    {
        // Following the previous tick: cursor at 10:00:30, now advances one interval → one fire.
        $schedule = $this->makeSchedule(MisfirePolicy::skipMissed());
        $cursor   = new DateTimeImmutable('2026-07-01T10:00:30Z');
        $now      = new DateTimeImmutable('2026-07-01T11:00:31Z');

        $result = $this->resolver->resolve($schedule, $cursor, $now);

        self::assertCount(1, $result->fires, 'exactly one boundary (11:00) fires under SkipMissed');
    }

    // ─────────────────────────────────────────────────────────────
    // Tenant propagation
    // ─────────────────────────────────────────────────────────────

    public function test_fires_carry_tenant_id(): void
    {
        $schedule = $this->makeSchedule(MisfirePolicy::fireOnceNow(), tenantId: 'tenant-x');
        $now      = new DateTimeImmutable('2026-07-01T11:00:01Z');
        $cursor   = new DateTimeImmutable('2026-07-01T10:00:00Z');

        $result = $this->resolver->resolve($schedule, $cursor, $now);

        self::assertCount(1, $result->fires);
        self::assertSame('tenant-x', $result->fires[0]->tenantId);
    }

    public function test_fires_carry_null_tenant_for_system_schedule(): void
    {
        $schedule = $this->makeSchedule(MisfirePolicy::fireOnceNow(), tenantId: null);
        $now      = new DateTimeImmutable('2026-07-01T11:00:01Z');
        $cursor   = new DateTimeImmutable('2026-07-01T10:00:00Z');

        $result = $this->resolver->resolve($schedule, $cursor, $now);

        self::assertCount(1, $result->fires);
        self::assertNull($result->fires[0]->tenantId);
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    private function makeSchedule(MisfirePolicy $misfire, ?string $tenantId = 'ta'): Schedule
    {
        return new Schedule(
            id:        ScheduleId::generate(),
            name:      'test-misfire-schedule',
            source:    ScheduleSource::Static,
            trigger:   new IntervalTrigger(3600), // every hour
            command:   new CommandSpec('Vortos\Scheduler\Tests\Unit\Engine\FakeCommand'),
            misfire:   $misfire,
            overlap:   OverlapPolicy::AllowConcurrent,
            timezone:  $this->utcTz,
            jitter:    null,
            status:    ScheduleStatus::Active,
            tenantId:  $tenantId,
        );
    }

    private function slotKey(Schedule $schedule, DateTimeImmutable $at): string
    {
        return $this->slotCalc->slotKey($schedule->id, $at, $schedule->timezone);
    }
}
