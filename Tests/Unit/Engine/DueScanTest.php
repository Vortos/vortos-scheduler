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
 *
 * DueScan is fed a map of scheduleId → cadence cursor (DateTimeImmutable) and returns fires,
 * dropped slots, and the advanced cursors to persist.
 */
final class DueScanTest extends TestCase
{
    private DueScan        $scan;
    private SlotCalculator $slotCalc;
    private DateTimeZone   $utcTz;

    protected function setUp(): void
    {
        $this->utcTz    = new DateTimeZone('UTC');
        $this->slotCalc = new SlotCalculator();
        $resolver       = new MisfireResolver($this->slotCalc);
        $this->scan     = new DueScan($resolver, 86400);
    }

    private function cursor(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-01T10:00:00Z');
    }

    // ─────────────────────────────────────────────────────────────
    // Basic behavior
    // ─────────────────────────────────────────────────────────────

    public function test_empty_schedules_returns_empty_result(): void
    {
        $result = $this->scan->compute([], [], new DateTimeImmutable());

        self::assertSame([], $result->fires);
        self::assertSame([], $result->dropped);
        self::assertSame([], $result->newCursors);
    }

    public function test_single_schedule_due_once_returns_one_fire(): void
    {
        $schedule = $this->makeSchedule();
        $now      = new DateTimeImmutable('2026-07-01T11:00:01Z');

        $result = $this->scan->compute(
            [$schedule],
            [$schedule->id->toString() => $this->cursor()],
            $now,
        );

        self::assertTrue($result->hasFires());
        self::assertCount(1, $result->fires);
        self::assertTrue($schedule->id->equals($result->fires[0]->scheduleId));
        // Cursor advanced for the evaluated schedule.
        self::assertArrayHasKey($schedule->id->toString(), $result->newCursors);
        self::assertEquals($now, $result->newCursors[$schedule->id->toString()]);
    }

    public function test_paused_schedule_is_excluded(): void
    {
        $active = $this->makeSchedule(status: ScheduleStatus::Active, name: 'active-sched');
        $paused = $this->makeSchedule(status: ScheduleStatus::Paused, name: 'paused-sched');
        $now    = new DateTimeImmutable('2026-07-01T11:00:01Z');

        $result = $this->scan->compute(
            [$active, $paused],
            [
                $active->id->toString() => $this->cursor(),
                $paused->id->toString() => $this->cursor(),
            ],
            $now,
        );

        self::assertCount(1, $result->fires);
        self::assertTrue($active->id->equals($result->fires[0]->scheduleId));
        // No cursor advance for the excluded (paused) schedule.
        self::assertArrayNotHasKey($paused->id->toString(), $result->newCursors);
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
        $s1  = $this->makeSchedule(name: 'sched-a');
        $s2  = $this->makeSchedule(name: 'sched-b');
        $now = new DateTimeImmutable('2026-07-01T11:00:01Z');

        $result = $this->scan->compute(
            [$s1, $s2],
            [
                $s1->id->toString() => $this->cursor(),
                $s2->id->toString() => $this->cursor(),
            ],
            $now,
        );

        self::assertCount(2, $result->fires);
    }

    public function test_never_scanned_schedule_anchored_to_now_yields_no_catchup(): void
    {
        // No cursor entry → DueScan anchors to `now`, so there is nothing due yet (no retroactive
        // catch-up from the horizon). Regression guard for Bug A.
        $schedule = $this->makeSchedule();
        $now      = new DateTimeImmutable('2026-07-01T11:00:01Z');

        $result = $this->scan->compute([$schedule], [], $now);

        self::assertCount(0, $result->fires);
        self::assertCount(0, $result->dropped);
        self::assertEquals($now, $result->newCursors[$schedule->id->toString()]);
    }

    // ─────────────────────────────────────────────────────────────
    // Shard pre-filter
    // ─────────────────────────────────────────────────────────────

    public function test_shard_filter_partitions_schedules(): void
    {
        $schedules = [];
        for ($i = 0; $i < 10; $i++) {
            $schedules[] = $this->makeSchedule(name: 'shard-test-' . $i);
        }

        $now     = new DateTimeImmutable('2026-07-01T11:00:01Z');
        $cursors = [];
        foreach ($schedules as $s) {
            $cursors[$s->id->toString()] = $this->cursor();
        }

        $shard0 = $this->scan->compute($schedules, $cursors, $now, shardIndex: 0, shardCount: 2);
        $shard1 = $this->scan->compute($schedules, $cursors, $now, shardIndex: 1, shardCount: 2);

        $ids0 = array_map(fn ($f) => $f->scheduleId->toString(), $shard0->fires);
        $ids1 = array_map(fn ($f) => $f->scheduleId->toString(), $shard1->fires);

        self::assertSame(0, count(array_intersect($ids0, $ids1)), 'Shards must not overlap');
        self::assertCount(10, array_merge($ids0, $ids1), 'Shards must together cover all schedules');
    }

    public function test_shard_null_shard_count_processes_all(): void
    {
        $s1  = $this->makeSchedule(name: 'all-a');
        $s2  = $this->makeSchedule(name: 'all-b');
        $now = new DateTimeImmutable('2026-07-01T11:00:01Z');

        $result = $this->scan->compute(
            [$s1, $s2],
            [
                $s1->id->toString() => $this->cursor(),
                $s2->id->toString() => $this->cursor(),
            ],
            $now,
            shardIndex: null,
            shardCount: null,
        );

        self::assertCount(2, $result->fires);
    }

    public function test_shard_count_one_processes_all(): void
    {
        $s1  = $this->makeSchedule(name: 'one-a');
        $s2  = $this->makeSchedule(name: 'one-b');
        $now = new DateTimeImmutable('2026-07-01T11:00:01Z');

        $result = $this->scan->compute(
            [$s1, $s2],
            [
                $s1->id->toString() => $this->cursor(),
                $s2->id->toString() => $this->cursor(),
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
        $now      = new DateTimeImmutable('2026-07-01T10:30:00Z'); // nothing due yet

        $result = $this->scan->compute([$schedule], [$schedule->id->toString() => $this->cursor()], $now);

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
}
