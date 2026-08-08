<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Testing;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Store\ScheduleCursorStoreInterface;

/**
 * Shared conformance suite for ScheduleCursorStoreInterface. Every driver (DBAL, in-memory) must
 * pass this identically. Each test uses freshly generated schedule ids so a shared test database
 * never cross-contaminates.
 */
abstract class ScheduleCursorStoreConformanceTestCase extends TestCase
{
    abstract protected function createStore(): ScheduleCursorStoreInterface;

    private function utc(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    public function test_find_cursors_empty_input_returns_empty(): void
    {
        self::assertSame([], $this->createStore()->findCursors([], null));
    }

    public function test_find_cursors_missing_schedule_is_absent(): void
    {
        $store = $this->createStore();
        $id    = ScheduleId::generate();

        self::assertArrayNotHasKey($id->toString(), $store->findCursors([$id], null));
    }

    public function test_advance_inserts_fresh_cursor_at_version_zero(): void
    {
        $store = $this->createStore();
        $id    = ScheduleId::generate();
        $at    = $this->utc('2026-07-01T10:00:00Z');

        self::assertTrue($store->advance($id, 'ta', $at, 0));

        $cursors = $store->findCursors([$id], null);
        self::assertArrayHasKey($id->toString(), $cursors);
        $cursor = $cursors[$id->toString()];
        self::assertEquals($at, $cursor->cursorAt);
        self::assertSame(1, $cursor->version);
        self::assertSame('ta', $cursor->tenantId);
    }

    /**
     * first_seen_at must be recorded on the insert that first anchors a schedule.
     *
     * Not bookkeeping: the overdue check reads this to tell a schedule that has never dispatched
     * because it is NEW from one that has never dispatched because it is BROKEN. Nothing in this
     * suite covered it, and under that gap the in-memory driver never populated it at all while the
     * DBAL driver did — two drivers disagreeing about a field an alarm depends on.
     */
    public function test_advance_records_first_seen_on_insert(): void
    {
        $store = $this->createStore();
        $id    = ScheduleId::generate();

        self::assertTrue($store->advance($id, 'ta', $this->utc('2026-07-01T10:00:00Z'), 0));

        $cursor = $store->findCursors([$id], null)[$id->toString()];
        self::assertNotNull($cursor->firstSeenAt, 'A freshly anchored cursor must record when it was first seen.');
    }

    /**
     * first_seen_at survives an advance.
     *
     * Preserved-once-set is the point: it records first sight, not last movement, so advancing the
     * cursor must never push it forward — that would keep resetting the baseline and no schedule
     * would ever look old enough to be judged dead.
     */
    public function test_advance_preserves_first_seen_across_updates(): void
    {
        $store = $this->createStore();
        $id    = ScheduleId::generate();

        self::assertTrue($store->advance($id, 'ta', $this->utc('2026-07-01T10:00:00Z'), 0));
        $first = $store->findCursors([$id], null)[$id->toString()]->firstSeenAt;
        self::assertNotNull($first);

        self::assertTrue($store->advance($id, 'ta', $this->utc('2026-07-01T11:00:00Z'), 1));
        $after = $store->findCursors([$id], null)[$id->toString()];

        self::assertNotNull($after->firstSeenAt);
        self::assertSame(
            $first->getTimestamp(),
            $after->firstSeenAt->getTimestamp(),
            'first_seen_at records first sight and must not advance with the cursor.',
        );
        self::assertEquals($this->utc('2026-07-01T11:00:00Z'), $after->cursorAt, 'The cursor itself must still move.');
    }

    public function test_advance_insert_lost_race_returns_false_when_already_present(): void
    {
        $store = $this->createStore();
        $id    = ScheduleId::generate();

        self::assertTrue($store->advance($id, 'ta', $this->utc('2026-07-01T10:00:00Z'), 0));
        // A second node also thinks it is fresh (version 0) → must lose.
        self::assertFalse($store->advance($id, 'ta', $this->utc('2026-07-01T11:00:00Z'), 0));

        // Cursor unchanged by the lost race.
        self::assertEquals(
            $this->utc('2026-07-01T10:00:00Z'),
            $store->findCursors([$id], null)[$id->toString()]->cursorAt,
        );
    }

    public function test_advance_cas_update_succeeds_on_matching_version(): void
    {
        $store = $this->createStore();
        $id    = ScheduleId::generate();

        $store->advance($id, 'ta', $this->utc('2026-07-01T10:00:00Z'), 0); // → version 1

        self::assertTrue($store->advance($id, 'ta', $this->utc('2026-07-01T11:00:00Z'), 1));

        $cursor = $store->findCursors([$id], null)[$id->toString()];
        self::assertEquals($this->utc('2026-07-01T11:00:00Z'), $cursor->cursorAt);
        self::assertSame(2, $cursor->version);
    }

    public function test_advance_cas_update_fails_on_stale_version(): void
    {
        $store = $this->createStore();
        $id    = ScheduleId::generate();

        $store->advance($id, 'ta', $this->utc('2026-07-01T10:00:00Z'), 0); // → version 1

        // Another node already advanced to version 2 underneath us.
        self::assertTrue($store->advance($id, 'ta', $this->utc('2026-07-01T11:00:00Z'), 1));

        // Our stale expectedVersion (1) must now fail.
        self::assertFalse($store->advance($id, 'ta', $this->utc('2026-07-01T12:00:00Z'), 1));

        self::assertEquals(
            $this->utc('2026-07-01T11:00:00Z'),
            $store->findCursors([$id], null)[$id->toString()]->cursorAt,
        );
    }

    public function test_find_cursors_tenant_scope_filters(): void
    {
        $store = $this->createStore();
        $id    = ScheduleId::generate();
        $store->advance($id, 'tenant-a', $this->utc('2026-07-01T10:00:00Z'), 0);

        self::assertArrayHasKey($id->toString(), $store->findCursors([$id], 'tenant-a'));
        self::assertArrayNotHasKey($id->toString(), $store->findCursors([$id], 'tenant-b'));
        // Daemon mode (null tenant) sees it regardless.
        self::assertArrayHasKey($id->toString(), $store->findCursors([$id], null));
    }

    public function test_find_cursors_returns_multiple(): void
    {
        $store = $this->createStore();
        $id1   = ScheduleId::generate();
        $id2   = ScheduleId::generate();
        $store->advance($id1, null, $this->utc('2026-07-01T10:00:00Z'), 0);
        $store->advance($id2, null, $this->utc('2026-07-01T11:00:00Z'), 0);

        $cursors = $store->findCursors([$id1, $id2], null);
        self::assertCount(2, $cursors);
        self::assertArrayHasKey($id1->toString(), $cursors);
        self::assertArrayHasKey($id2->toString(), $cursors);
    }

    public function test_null_tenant_cursor_round_trips(): void
    {
        $store = $this->createStore();
        $id    = ScheduleId::generate();
        $store->advance($id, null, $this->utc('2026-07-01T10:00:00Z'), 0);

        self::assertNull($store->findCursors([$id], null)[$id->toString()]->tenantId);
    }
}
