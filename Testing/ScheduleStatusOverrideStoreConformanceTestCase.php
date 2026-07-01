<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Testing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Store\ScheduleStatusOverride;
use Vortos\Scheduler\Store\ScheduleStatusOverrideStoreInterface;

abstract class ScheduleStatusOverrideStoreConformanceTestCase extends TestCase
{
    abstract protected function createStore(): ScheduleStatusOverrideStoreInterface;

    private ScheduleStatusOverrideStoreInterface $store;

    protected function setUp(): void
    {
        $this->store = $this->createStore();
    }

    // ── Group A — basic CRUD ─────────────────────────────────────────────────

    public function test_save_and_find_round_trip(): void
    {
        $id  = ScheduleId::generate();
        $now = new DateTimeImmutable('2026-07-01T10:00:00+00:00');
        $override = new ScheduleStatusOverride($id, ScheduleStatus::Paused, 'actor-1', 'maintenance', $now);

        $this->store->save($override);
        $found = $this->store->find($id);

        self::assertNotNull($found);
        self::assertSame($id->toString(), $found->scheduleId->toString());
        self::assertSame(ScheduleStatus::Paused, $found->status);
        self::assertSame('actor-1', $found->actorId);
        self::assertSame('maintenance', $found->reason);
    }

    public function test_find_missing_returns_null(): void
    {
        self::assertNull($this->store->find(ScheduleId::generate()));
    }

    public function test_remove_then_find_returns_null(): void
    {
        $id = ScheduleId::generate();
        $this->store->save(new ScheduleStatusOverride($id, ScheduleStatus::Paused, 'a', null, new DateTimeImmutable()));
        $this->store->remove($id);
        self::assertNull($this->store->find($id));
    }

    public function test_remove_missing_id_does_not_throw(): void
    {
        $this->store->remove(ScheduleId::generate());
        $this->addToAssertionCount(1);
    }

    // ── Group B — upsert semantics ───────────────────────────────────────────

    public function test_save_twice_second_write_wins(): void
    {
        $id  = ScheduleId::generate();
        $now = new DateTimeImmutable('2026-07-01T10:00:00+00:00');
        $this->store->save(new ScheduleStatusOverride($id, ScheduleStatus::Paused, 'actor-1', 'first', $now));
        $this->store->save(new ScheduleStatusOverride($id, ScheduleStatus::Paused, 'actor-2', 'second', $now->modify('+1 hour')));

        $found = $this->store->find($id);
        self::assertSame('actor-2', $found?->actorId);
        self::assertSame('second', $found?->reason);
    }

    public function test_save_paused_then_active(): void
    {
        $id  = ScheduleId::generate();
        $now = new DateTimeImmutable('2026-07-01T10:00:00+00:00');
        $this->store->save(new ScheduleStatusOverride($id, ScheduleStatus::Paused, 'a', null, $now));
        $this->store->save(new ScheduleStatusOverride($id, ScheduleStatus::Active, 'b', null, $now->modify('+1 hour')));

        $found = $this->store->find($id);
        self::assertSame(ScheduleStatus::Active, $found?->status);
    }

    // ── Group C — findAllPaused ──────────────────────────────────────────────

    public function test_find_all_paused_empty(): void
    {
        self::assertSame([], $this->store->findAllPaused());
    }

    public function test_find_all_paused_returns_only_paused(): void
    {
        $id1 = ScheduleId::generate();
        $id2 = ScheduleId::generate();
        $id3 = ScheduleId::generate();
        $now = new DateTimeImmutable('2026-07-01T10:00:00+00:00');

        $this->store->save(new ScheduleStatusOverride($id1, ScheduleStatus::Paused, 'a', null, $now));
        $this->store->save(new ScheduleStatusOverride($id2, ScheduleStatus::Paused, 'b', null, $now));
        $this->store->save(new ScheduleStatusOverride($id3, ScheduleStatus::Active, 'c', null, $now));

        $paused = $this->store->findAllPaused();
        self::assertCount(2, $paused);
        $ids = array_map(fn($o) => $o->scheduleId->toString(), $paused);
        self::assertContains($id1->toString(), $ids);
        self::assertContains($id2->toString(), $ids);
        self::assertNotContains($id3->toString(), $ids);
    }

    public function test_find_all_paused_returns_full_fields(): void
    {
        $id  = ScheduleId::generate();
        $now = new DateTimeImmutable('2026-07-01T10:00:00+00:00');
        $this->store->save(new ScheduleStatusOverride($id, ScheduleStatus::Paused, 'actor-x', 'reason-y', $now));

        $paused = $this->store->findAllPaused();
        self::assertCount(1, $paused);
        self::assertSame('actor-x', $paused[0]->actorId);
        self::assertSame('reason-y', $paused[0]->reason);
    }
}
