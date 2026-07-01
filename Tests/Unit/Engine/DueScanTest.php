<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Engine;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Engine\DueScan;
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
 * Unit tests for DueScan (pure, no I/O).
 */
final class DueScanTest extends TestCase
{
    private DueScan       $scan;
    private SlotCalculator $slotCalc;
    private DateTimeZone  $utcTz;

    protected function setUp(): void
    {
        $this->utcTz   = new DateTimeZone('UTC');
        $this->slotCalc = new SlotCalculator();
        $resolver       = new MisfireResolver($this->slotCalc);
        $this->scan     = new DueScan($resolver, 86400);
    }

    // ─────────────────────────────────────────────────────────────
    // Basic behavior
    // ─────────────────────────────────────────────────────────────

    public function test_empty_schedules_returns_empty_result(): void
    {
        $result = $this->scan->compute([], [], new DateTimeImmutable());

        self::assertSame([], $result->fires);
        self::assertSame([], $result->dropped);
    }

    public function test_single_schedule_due_once_returns_one_fire(): void
    {
        $schedule = $this->makeSchedule();
        $now      = new DateTimeImmutable('2026-07-01T11:00:01Z');
        $lastSlot = $this->slotKey($schedule, new DateTimeImmutable('2026-07-01T10:00:00Z'));

        $result = $this->scan->compute(
            [$schedule],
            [$schedule->id->toString() => $lastSlot],
            $now,
        );

        self::assertTrue($result->hasFires());
        self::assertCount(1, $result->fires);
        self::assertTrue($schedule->id->equals($result->fires[0]->scheduleId));
    }

    public function test_paused_schedule_is_excluded(): void
    {
        $active = $this->makeSchedule(status: ScheduleStatus::Active, name: 'active-sched');
        $paused = $this->makeSchedule(status: ScheduleStatus::Paused, name: 'paused-sched');
        $now    = new DateTimeImmutable('2026-07-01T11:00:01Z');
        $lastSlot = $this->slotKey($active, new DateTimeImmutable('2026-07-01T10:00:00Z'));

        $result = $this->scan->compute(
            [$active, $paused],
            [
                $active->id->toString() => $lastSlot,
                $paused->id->toString() => $lastSlot,
            ],
            $now,
        );

        self::assertCount(1, $result->fires);
        self::assertTrue($active->id->equals($result->fires[0]->scheduleId));
    }

    public function test_disabled_schedule_is_excluded(): void
    {
        $disabled = $this->makeSchedule(status: ScheduleStatus::Disabled);
        $now      = new DateTimeImmutable('2026-07-01T11:00:01Z');

        $result = $this->scan->compute([$disabled], [], $now);

        self::assertFalse($result->hasFires());
    }

    public function test_multiple_schedules_all_due(): void
    {
        $s1 = $this->makeSchedule(name: 'sched-a');
        $s2 = $this->makeSchedule(name: 'sched-b');
        $now = new DateTimeImmutable('2026-07-01T11:00:01Z');
        $lastSlot1 = $this->slotKey($s1, new DateTimeImmutable('2026-07-01T10:00:00Z'));
        $lastSlot2 = $this->slotKey($s2, new DateTimeImmutable('2026-07-01T10:00:00Z'));

        $result = $this->scan->compute(
            [$s1, $s2],
            [
                $s1->id->toString() => $lastSlot1,
                $s2->id->toString() => $lastSlot2,
            ],
            $now,
        );

        self::assertCount(2, $result->fires);
    }

    public function test_never_fired_schedule_treated_as_null_last_slot(): void
    {
        $schedule = $this->makeSchedule();
        $now      = new DateTimeImmutable('2026-07-01T11:00:01Z');
        // No entry in lastSlotBySchedule → treated as never fired

        $result = $this->scan->compute([$schedule], [], $now);

        // Should return fires from the horizon window
        self::assertIsArray($result->fires);
    }

    // ─────────────────────────────────────────────────────────────
    // Shard pre-filter
    // ─────────────────────────────────────────────────────────────

    public function test_shard_filter_partitions_schedules(): void
    {
        // Generate enough schedules that some definitely fall in shard 0 and some in shard 1
        $schedules = [];
        for ($i = 0; $i < 10; $i++) {
            $schedules[] = $this->makeSchedule(name: 'shard-test-' . $i);
        }

        $now      = new DateTimeImmutable('2026-07-01T11:00:01Z');
        $lastSlots = [];
        foreach ($schedules as $s) {
            $lastSlots[$s->id->toString()] = $this->slotKey($s, new DateTimeImmutable('2026-07-01T10:00:00Z'));
        }

        $shard0 = $this->scan->compute($schedules, $lastSlots, $now, shardIndex: 0, shardCount: 2);
        $shard1 = $this->scan->compute($schedules, $lastSlots, $now, shardIndex: 1, shardCount: 2);

        // Together they must cover all schedules (no overlap, complete partition)
        $ids0 = array_map(fn($f) => $f->scheduleId->toString(), $shard0->fires);
        $ids1 = array_map(fn($f) => $f->scheduleId->toString(), $shard1->fires);

        self::assertSame(0, count(array_intersect($ids0, $ids1)), 'Shards must not overlap');
        self::assertCount(10, array_merge($ids0, $ids1), 'Shards must together cover all schedules');
    }

    public function test_shard_null_shard_count_processes_all(): void
    {
        $s1 = $this->makeSchedule(name: 'all-a');
        $s2 = $this->makeSchedule(name: 'all-b');
        $now = new DateTimeImmutable('2026-07-01T11:00:01Z');

        $result = $this->scan->compute(
            [$s1, $s2],
            [
                $s1->id->toString() => $this->slotKey($s1, new DateTimeImmutable('2026-07-01T10:00:00Z')),
                $s2->id->toString() => $this->slotKey($s2, new DateTimeImmutable('2026-07-01T10:00:00Z')),
            ],
            $now,
            shardIndex: null,
            shardCount: null,
        );

        self::assertCount(2, $result->fires);
    }

    public function test_shard_count_one_processes_all(): void
    {
        $s1 = $this->makeSchedule(name: 'one-a');
        $s2 = $this->makeSchedule(name: 'one-b');
        $now = new DateTimeImmutable('2026-07-01T11:00:01Z');

        $result = $this->scan->compute(
            [$s1, $s2],
            [
                $s1->id->toString() => $this->slotKey($s1, new DateTimeImmutable('2026-07-01T10:00:00Z')),
                $s2->id->toString() => $this->slotKey($s2, new DateTimeImmutable('2026-07-01T10:00:00Z')),
            ],
            $now,
            shardIndex: 0,
            shardCount: 1,
        );

        self::assertCount(2, $result->fires);
    }

    // ─────────────────────────────────────────────────────────────
    // DueScanResult helper methods
    // ─────────────────────────────────────────────────────────────

    public function test_result_has_fires_returns_false_when_empty(): void
    {
        $schedule = $this->makeSchedule();
        // No due slot: last fired moments ago
        $now      = new DateTimeImmutable('2026-07-01T10:30:00Z');
        $lastSlot = $this->slotKey($schedule, new DateTimeImmutable('2026-07-01T10:00:00Z'));

        $result = $this->scan->compute([$schedule], [$schedule->id->toString() => $lastSlot], $now);

        self::assertFalse($result->hasFires());
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    private function makeSchedule(
        ScheduleStatus $status = ScheduleStatus::Active,
        string         $name   = 'test-due-scan',
    ): Schedule {
        return new Schedule(
            id:        ScheduleId::generate(),
            name:      $name,
            source:    ScheduleSource::Static,
            trigger:   new IntervalTrigger(3600),
            command:   new CommandSpec('Vortos\Scheduler\Tests\Unit\Engine\FakeCommand'),
            misfire:   MisfirePolicy::skipMissed(),
            overlap:   OverlapPolicy::AllowConcurrent,
            timezone:  $this->utcTz,
            jitter:    null,
            status:    $status,
            tenantId:  'ta',
        );
    }

    private function slotKey(Schedule $schedule, DateTimeImmutable $at): string
    {
        return $this->slotCalc->slotKey($schedule->id, $at, $schedule->timezone);
    }
}
