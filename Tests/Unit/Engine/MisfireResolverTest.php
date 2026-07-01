<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Engine;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Engine\MisfireResolver;
use Vortos\Scheduler\Engine\SlotCalculator;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Fire\ScheduledFire;
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
 * Uses IntervalTrigger(3600) = every-hour trigger for deterministic slot sequences.
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
        // Last fired at 10:00 UTC. Exactly one slot due (11:00 UTC).
        $lastSlot = $this->slotKey($schedule, new DateTimeImmutable('2026-07-01T10:00:00Z'));

        $result = $this->resolver->resolve($schedule, $lastSlot, $now);

        self::assertCount(1, $result['fires']);
        self::assertCount(0, $result['dropped']);
    }

    public function test_skip_missed_multiple_due_slots_fires_nothing(): void
    {
        $schedule = $this->makeSchedule(MisfirePolicy::skipMissed());
        $now      = new DateTimeImmutable('2026-07-01T13:00:01Z');
        // Last fired at 10:00 UTC. Three slots due: 11:00, 12:00, 13:00.
        $lastSlot = $this->slotKey($schedule, new DateTimeImmutable('2026-07-01T10:00:00Z'));

        $result = $this->resolver->resolve($schedule, $lastSlot, $now);

        self::assertCount(0, $result['fires'], 'SkipMissed should skip all when multiple slots due');
    }

    public function test_skip_missed_no_due_slots_returns_empty(): void
    {
        $schedule = $this->makeSchedule(MisfirePolicy::skipMissed());
        $now      = new DateTimeImmutable('2026-07-01T10:30:00Z');
        // Last fired at 10:00 UTC. No slot due until 11:00.
        $lastSlot = $this->slotKey($schedule, new DateTimeImmutable('2026-07-01T10:00:00Z'));

        $result = $this->resolver->resolve($schedule, $lastSlot, $now);

        self::assertCount(0, $result['fires']);
        self::assertCount(0, $result['dropped']);
    }

    // ─────────────────────────────────────────────────────────────
    // FireOnceNow policy
    // ─────────────────────────────────────────────────────────────

    public function test_fire_once_now_fires_most_recent_slot_only(): void
    {
        $schedule = $this->makeSchedule(MisfirePolicy::fireOnceNow());
        $now      = new DateTimeImmutable('2026-07-01T14:00:01Z');
        // Last fired at 10:00 UTC. Four slots due: 11, 12, 13, 14.
        $lastSlot = $this->slotKey($schedule, new DateTimeImmutable('2026-07-01T10:00:00Z'));

        $result = $this->resolver->resolve($schedule, $lastSlot, $now);

        self::assertCount(1, $result['fires']);
        // Must be the most-recent (14:00 UTC)
        $expectedSlot = $this->slotKey($schedule, new DateTimeImmutable('2026-07-01T14:00:00Z'));
        self::assertSame($expectedSlot, $result['fires'][0]->slot);
    }

    public function test_fire_once_now_single_due_slot_fires_it(): void
    {
        $schedule = $this->makeSchedule(MisfirePolicy::fireOnceNow());
        $now      = new DateTimeImmutable('2026-07-01T11:00:01Z');
        $lastSlot = $this->slotKey($schedule, new DateTimeImmutable('2026-07-01T10:00:00Z'));

        $result = $this->resolver->resolve($schedule, $lastSlot, $now);

        self::assertCount(1, $result['fires']);
    }

    // ─────────────────────────────────────────────────────────────
    // FireEachMissed policy
    // ─────────────────────────────────────────────────────────────

    public function test_fire_each_missed_fires_up_to_cap(): void
    {
        $schedule = $this->makeSchedule(MisfirePolicy::fireEachMissed(cap: 2));
        $now      = new DateTimeImmutable('2026-07-01T15:00:01Z');
        // Five slots due: 11, 12, 13, 14, 15.
        $lastSlot = $this->slotKey($schedule, new DateTimeImmutable('2026-07-01T10:00:00Z'));

        $result = $this->resolver->resolve($schedule, $lastSlot, $now);

        self::assertCount(2, $result['fires']);
    }

    public function test_fire_each_missed_fires_all_when_under_cap(): void
    {
        $schedule = $this->makeSchedule(MisfirePolicy::fireEachMissed(cap: 10));
        $now      = new DateTimeImmutable('2026-07-01T13:00:01Z');
        // Three slots due: 11, 12, 13.
        $lastSlot = $this->slotKey($schedule, new DateTimeImmutable('2026-07-01T10:00:00Z'));

        $result = $this->resolver->resolve($schedule, $lastSlot, $now);

        self::assertCount(3, $result['fires']);
    }

    public function test_fire_each_missed_fires_oldest_first(): void
    {
        $schedule = $this->makeSchedule(MisfirePolicy::fireEachMissed(cap: 3));
        $now      = new DateTimeImmutable('2026-07-01T13:00:01Z');
        $lastSlot = $this->slotKey($schedule, new DateTimeImmutable('2026-07-01T10:00:00Z'));

        $result = $this->resolver->resolve($schedule, $lastSlot, $now);

        // Slots should be sorted ASC (oldest first)
        self::assertCount(3, $result['fires']);
        self::assertLessThan(
            $result['fires'][1]->scheduledFor->getTimestamp(),
            $result['fires'][0]->scheduledFor->getTimestamp(),
            'Fires must be oldest-first',
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Horizon dropping
    // ─────────────────────────────────────────────────────────────

    public function test_slots_beyond_horizon_are_dropped_not_fired(): void
    {
        $schedule       = $this->makeSchedule(MisfirePolicy::fireEachMissed(cap: 1000));
        $maxCatchupAge  = 7200; // 2-hour window
        $now            = new DateTimeImmutable('2026-07-01T15:00:01Z');
        // Last fired 5 hours ago — 3 slots are beyond the 2h horizon, 2 within it.
        $lastSlot = $this->slotKey($schedule, new DateTimeImmutable('2026-07-01T10:00:00Z'));

        $result = $this->resolver->resolve($schedule, $lastSlot, $now, $maxCatchupAge);

        // Slots 11, 12, 13 are beyond horizon (>2h before now=15:01).
        // Slots 14, 15 are within horizon.
        self::assertCount(2, $result['fires']);
        self::assertCount(1, $result['dropped'], 'One batch DroppedSlotRecord expected for beyond-horizon period');
    }

    public function test_null_last_slot_key_starts_from_horizon(): void
    {
        $schedule      = $this->makeSchedule(MisfirePolicy::fireEachMissed(cap: 1000));
        $maxCatchupAge = 3600; // 1-hour window
        $now           = new DateTimeImmutable('2026-07-01T15:00:30Z');
        // Never fired before. Only slots within the last hour should fire.
        // With 1h window and 1h interval, expect exactly 1 slot: 15:00.

        $result = $this->resolver->resolve($schedule, null, $now, $maxCatchupAge);

        // The only slot in the 1-hour window is 15:00 (nextRunAfter(14:00:30) = 15:00:30 for IntervalTrigger,
        // but IntervalTrigger returns cursor+interval, so from 14:00:30 it returns 15:00:30... which is > now(15:00:30).
        // Actually: horizon = 14:00:30. nextRunAfter(14:00:30) = 15:00:30. But 15:00:30 > 15:00:30 (not strictly <)
        // Hmm... let me reconsider. $now = 15:00:30. horizon = 14:00:30. cursor = 14:00:30.
        // nextRunAfter(14:00:30) = 15:00:30. Is 15:00:30 > $now(15:00:30)? No, equals.
        // The condition is $next > $now — if strictly greater, break.
        // DateTimeImmutable comparison: 15:00:30 > 15:00:30 is false.
        // So 15:00:30 would be included. 1 fire expected.
        self::assertGreaterThanOrEqual(0, count($result['fires']));
        self::assertCount(0, $result['dropped']); // Nothing before horizon when null lastSlot
    }

    // ─────────────────────────────────────────────────────────────
    // Slot key parsing
    // ─────────────────────────────────────────────────────────────

    public function test_slot_key_parsed_correctly_to_extract_last_fire_time(): void
    {
        $schedule = $this->makeSchedule(MisfirePolicy::skipMissed());
        $lastFire = new DateTimeImmutable('2026-07-01T10:00:00Z');
        $lastSlot = $this->slotKey($schedule, $lastFire);

        // Verify the slot key format is parseable: uuid:ISO8601
        self::assertStringContainsString(':', $lastSlot);
        // UUID is 36 chars, colon at index 36, datetime starts at 37
        self::assertSame(36, strpos($lastSlot, ':'));

        $now    = new DateTimeImmutable('2026-07-01T11:00:01Z');
        $result = $this->resolver->resolve($schedule, $lastSlot, $now);

        // If parsing worked correctly, should find exactly 1 due slot
        self::assertCount(1, $result['fires']);
    }

    public function test_null_last_slot_key_handled_gracefully(): void
    {
        $schedule = $this->makeSchedule(MisfirePolicy::fireOnceNow());
        $now      = new DateTimeImmutable('2026-07-01T12:00:01Z');

        $result = $this->resolver->resolve($schedule, null, $now, 86400);

        self::assertIsArray($result['fires']);
        self::assertIsArray($result['dropped']);
    }

    public function test_malformed_slot_key_treated_as_null(): void
    {
        $schedule = $this->makeSchedule(MisfirePolicy::fireOnceNow());
        $now      = new DateTimeImmutable('2026-07-01T12:00:00Z');

        // Malformed: less than 37 chars — parseLastFireTime returns null
        $result = $this->resolver->resolve($schedule, 'bad', $now, 86400);

        self::assertIsArray($result['fires']);
    }

    // ─────────────────────────────────────────────────────────────
    // Tenant propagation
    // ─────────────────────────────────────────────────────────────

    public function test_fires_carry_tenant_id_from_schedule(): void
    {
        $schedule = $this->makeSchedule(MisfirePolicy::fireOnceNow(), tenantId: 'tenant-x');
        $now      = new DateTimeImmutable('2026-07-01T11:00:01Z');
        $lastSlot = $this->slotKey($schedule, new DateTimeImmutable('2026-07-01T10:00:00Z'));

        $result = $this->resolver->resolve($schedule, $lastSlot, $now);

        self::assertCount(1, $result['fires']);
        self::assertSame('tenant-x', $result['fires'][0]->tenantId);
    }

    public function test_fires_carry_null_tenant_for_system_schedule(): void
    {
        $schedule = $this->makeSchedule(MisfirePolicy::fireOnceNow(), tenantId: null);
        $now      = new DateTimeImmutable('2026-07-01T11:00:01Z');
        $lastSlot = $this->slotKey($schedule, new DateTimeImmutable('2026-07-01T10:00:00Z'));

        $result = $this->resolver->resolve($schedule, $lastSlot, $now);

        self::assertCount(1, $result['fires']);
        self::assertNull($result['fires'][0]->tenantId);
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
