<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Testing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Fire\IdempotencyKey;
use Vortos\Scheduler\Fire\RunState;
use Vortos\Scheduler\Fire\ScheduleRun;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Store\Exception\DuplicateSlotException;
use Vortos\Scheduler\Store\Exception\InvalidRunStateTransitionException;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;

/**
 * Shared conformance test base for all ScheduleRunStoreInterface drivers.
 *
 * Groups:
 *   A — insertRun idempotency (the exactly-once anchor)
 *   B — findLastSlots bulk lookup
 *   C — findRunState
 *   D — transitionRunState state-machine
 *   E — pruneOldRuns
 */
abstract class ScheduleRunStoreConformanceTestCase extends TestCase
{
    private ScheduleRunStoreInterface $store;

    abstract protected function createStore(): ScheduleRunStoreInterface;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore();
    }

    // ─────────────────────────────────────────────────────────────
    // Group A — insertRun idempotency
    // ─────────────────────────────────────────────────────────────

    final public function test_insert_run_succeeds_on_first_insert(): void
    {
        $run = $this->makeRun(scheduleId: ScheduleId::generate(), slot: 's1', tenantId: 'ta');

        $this->store->insertRun($run);

        // Verify via findRunState
        self::assertSame(RunState::Dispatched, $this->store->findRunState($run->scheduleId, $run->slot, 'ta'));
    }

    final public function test_duplicate_slot_throws_duplicate_slot_exception(): void
    {
        $id  = ScheduleId::generate();
        $run = $this->makeRun(scheduleId: $id, slot: 'dup-slot', tenantId: 'ta');

        $this->store->insertRun($run);

        $this->expectException(DuplicateSlotException::class);

        $this->store->insertRun($run);
    }

    final public function test_duplicate_slot_exception_carries_slot_and_schedule_id(): void
    {
        $id  = ScheduleId::generate();
        $run = $this->makeRun(scheduleId: $id, slot: 'exc-slot', tenantId: 'ta');

        $this->store->insertRun($run);

        try {
            $this->store->insertRun($run);
            self::fail('Expected DuplicateSlotException was not thrown.');
        } catch (DuplicateSlotException $e) {
            self::assertSame('exc-slot', $e->slot);
            self::assertTrue($id->equals($e->scheduleId));
        }
    }

    final public function test_different_slot_for_same_schedule_succeeds(): void
    {
        $id = ScheduleId::generate();

        $this->store->insertRun($this->makeRun(scheduleId: $id, slot: 'slot-x', tenantId: 'ta'));
        $this->store->insertRun($this->makeRun(scheduleId: $id, slot: 'slot-y', tenantId: 'ta'));

        $this->addToAssertionCount(1);
    }

    final public function test_same_slot_different_schedule_ids_succeeds(): void
    {
        $this->store->insertRun($this->makeRun(scheduleId: ScheduleId::generate(), slot: 'same-slot', tenantId: 'ta'));
        $this->store->insertRun($this->makeRun(scheduleId: ScheduleId::generate(), slot: 'same-slot', tenantId: 'ta'));

        $this->addToAssertionCount(1);
    }

    final public function test_same_slot_different_tenants_succeeds(): void
    {
        $id = ScheduleId::generate();

        $this->store->insertRun($this->makeRun(scheduleId: $id, slot: 'tenant-slot', tenantId: 'ta'));
        $this->store->insertRun($this->makeRun(scheduleId: $id, slot: 'tenant-slot', tenantId: 'tb'));

        $this->addToAssertionCount(1);
    }

    // ─────────────────────────────────────────────────────────────
    // Group B — findLastSlots
    // ─────────────────────────────────────────────────────────────

    final public function test_find_last_slots_empty_input_returns_empty_array(): void
    {
        self::assertSame([], $this->store->findLastSlots([], 'ta'));
    }

    final public function test_find_last_slots_never_fired_schedule_absent_from_result(): void
    {
        $id     = ScheduleId::generate();
        $result = $this->store->findLastSlots([$id], 'ta');

        self::assertArrayNotHasKey($id->toString(), $result);
    }

    final public function test_find_last_slots_returns_most_recent_slot(): void
    {
        $id   = ScheduleId::generate();
        $now  = new DateTimeImmutable('2026-07-01T10:00:00Z');
        $later = new DateTimeImmutable('2026-07-01T11:00:00Z');

        $this->store->insertRun($this->makeRun($id, 'slot-earlier', 'ta', $now));
        $this->store->insertRun($this->makeRun($id, 'slot-later',   'ta', $later));

        $result = $this->store->findLastSlots([$id], 'ta');

        self::assertSame('slot-later', $result[$id->toString()]);
    }

    final public function test_find_last_slots_bulk_multiple_schedules(): void
    {
        $idA = ScheduleId::generate();
        $idB = ScheduleId::generate();

        $now = new DateTimeImmutable('2026-07-01T10:00:00Z');
        $this->store->insertRun($this->makeRun($idA, 'slot-a', 'ta', $now));
        $this->store->insertRun($this->makeRun($idB, 'slot-b', 'ta', $now));

        $result = $this->store->findLastSlots([$idA, $idB], 'ta');

        self::assertSame('slot-a', $result[$idA->toString()]);
        self::assertSame('slot-b', $result[$idB->toString()]);
    }

    final public function test_find_last_slots_tenant_isolation(): void
    {
        $id  = ScheduleId::generate();
        $now = new DateTimeImmutable('2026-07-01T10:00:00Z');

        $this->store->insertRun($this->makeRun($id, 'slot-ta', 'ta', $now));
        $this->store->insertRun($this->makeRun($id, 'slot-tb', 'tb', $now));

        // tenant-a query should only see tenant-a's slot
        $resultA = $this->store->findLastSlots([$id], 'ta');
        self::assertSame('slot-ta', $resultA[$id->toString()]);

        // tenant-b query should only see tenant-b's slot
        $resultB = $this->store->findLastSlots([$id], 'tb');
        self::assertSame('slot-tb', $resultB[$id->toString()]);
    }

    final public function test_find_last_slots_daemon_mode_null_sees_all(): void
    {
        $id  = ScheduleId::generate();
        $now = new DateTimeImmutable('2026-07-01T10:00:00Z');

        $this->store->insertRun($this->makeRun($id, 'daemon-slot', 'ta', $now));

        // daemon mode: null tenantId = no filter
        $result = $this->store->findLastSlots([$id], null);

        self::assertArrayHasKey($id->toString(), $result);
    }

    // ─────────────────────────────────────────────────────────────
    // Group C — findRunState
    // ─────────────────────────────────────────────────────────────

    final public function test_find_run_state_returns_dispatched_on_insert(): void
    {
        $id  = ScheduleId::generate();
        $run = $this->makeRun($id, 'state-slot', 'ta');

        $this->store->insertRun($run);

        self::assertSame(RunState::Dispatched, $this->store->findRunState($id, 'state-slot', 'ta'));
    }

    final public function test_find_run_state_returns_null_for_unknown_slot(): void
    {
        self::assertNull($this->store->findRunState(ScheduleId::generate(), 'nonexistent', 'ta'));
    }

    final public function test_find_run_state_tenant_isolation(): void
    {
        $id  = ScheduleId::generate();
        $run = $this->makeRun($id, 'iso-state', 'ta');

        $this->store->insertRun($run);

        // Querying with a different tenant should return null
        self::assertNull($this->store->findRunState($id, 'iso-state', 'tb'));
    }

    // ─────────────────────────────────────────────────────────────
    // Group D — transitionRunState
    // ─────────────────────────────────────────────────────────────

    final public function test_transition_dispatched_to_completed(): void
    {
        $run = $this->makeRun(ScheduleId::generate(), 'trans-comp', 'ta');
        $this->store->insertRun($run);

        $this->store->transitionRunState($run->runId, RunState::Completed, new DateTimeImmutable());

        self::assertSame(
            RunState::Completed,
            $this->store->findRunState($run->scheduleId, $run->slot, 'ta'),
        );
    }

    final public function test_transition_dispatched_to_failed(): void
    {
        $run = $this->makeRun(ScheduleId::generate(), 'trans-fail', 'ta');
        $this->store->insertRun($run);

        $this->store->transitionRunState($run->runId, RunState::Failed, new DateTimeImmutable());

        self::assertSame(
            RunState::Failed,
            $this->store->findRunState($run->scheduleId, $run->slot, 'ta'),
        );
    }

    final public function test_transition_completed_to_failed_throws(): void
    {
        $run = $this->makeRun(ScheduleId::generate(), 'trans-c2f', 'ta');
        $this->store->insertRun($run);
        $this->store->transitionRunState($run->runId, RunState::Completed, new DateTimeImmutable());

        $this->expectException(InvalidRunStateTransitionException::class);

        $this->store->transitionRunState($run->runId, RunState::Failed, new DateTimeImmutable());
    }

    final public function test_transition_failed_to_completed_throws(): void
    {
        $run = $this->makeRun(ScheduleId::generate(), 'trans-f2c', 'ta');
        $this->store->insertRun($run);
        $this->store->transitionRunState($run->runId, RunState::Failed, new DateTimeImmutable());

        $this->expectException(InvalidRunStateTransitionException::class);

        $this->store->transitionRunState($run->runId, RunState::Completed, new DateTimeImmutable());
    }

    final public function test_transition_completed_to_completed_throws(): void
    {
        $run = $this->makeRun(ScheduleId::generate(), 'trans-c2c', 'ta');
        $this->store->insertRun($run);
        $this->store->transitionRunState($run->runId, RunState::Completed, new DateTimeImmutable());

        $this->expectException(InvalidRunStateTransitionException::class);

        $this->store->transitionRunState($run->runId, RunState::Completed, new DateTimeImmutable());
    }

    final public function test_transition_nonexistent_run_throws_runtime_exception(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->store->transitionRunState('nonexistent-run-id', RunState::Completed, new DateTimeImmutable());
    }

    // ─────────────────────────────────────────────────────────────
    // Group E — pruneOldRuns
    // ─────────────────────────────────────────────────────────────

    final public function test_prune_with_future_cutoff_deletes_nothing(): void
    {
        $run = $this->makeRun(ScheduleId::generate(), 'prune-none', 'ta');
        $this->store->insertRun($run);

        $result = $this->store->pruneOldRuns(new DateTimeImmutable('1970-01-01T00:00:00Z'));

        self::assertSame(0, $result->deletedCount);
        self::assertFalse($result->truncated);
    }

    final public function test_prune_terminal_run_older_than_cutoff(): void
    {
        $past = new DateTimeImmutable('2020-01-01T00:00:00Z');
        $run  = $this->makeRun(ScheduleId::generate(), 'prune-old', 'ta', $past);
        $this->store->insertRun($run);
        $this->store->transitionRunState($run->runId, RunState::Completed, $past);

        $result = $this->store->pruneOldRuns(new DateTimeImmutable('2021-01-01T00:00:00Z'));

        self::assertGreaterThanOrEqual(1, $result->deletedCount);
    }

    final public function test_prune_does_not_delete_dispatched_runs(): void
    {
        $past = new DateTimeImmutable('2020-01-01T00:00:00Z');
        $run  = $this->makeRun(ScheduleId::generate(), 'prune-dispatched', 'ta', $past);
        $this->store->insertRun($run);
        // intentionally NOT transitioning — stays dispatched

        $result = $this->store->pruneOldRuns(new DateTimeImmutable('2021-01-01T00:00:00Z'));

        self::assertSame(0, $result->deletedCount);

        // Confirm it's still there
        self::assertSame(
            RunState::Dispatched,
            $this->store->findRunState($run->scheduleId, $run->slot, 'ta'),
        );
    }

    final public function test_prune_scoped_to_tenant_id_only_deletes_that_tenant(): void
    {
        $past = new DateTimeImmutable('2020-01-01T00:00:00Z');

        $runA = $this->makeRun(ScheduleId::generate(), 'prune-tenant-a', 'tenant-a', $past);
        $runB = $this->makeRun(ScheduleId::generate(), 'prune-tenant-b', 'tenant-b', $past);
        $this->store->insertRun($runA);
        $this->store->insertRun($runB);
        $this->store->transitionRunState($runA->runId, RunState::Completed, $past);
        $this->store->transitionRunState($runB->runId, RunState::Completed, $past);

        $result = $this->store->pruneOldRuns(new DateTimeImmutable('2021-01-01T00:00:00Z'), 'tenant-a');

        self::assertSame(1, $result->deletedCount);
        self::assertNull($this->store->findRunBySlot($runA->scheduleId, 'prune-tenant-a', 'tenant-a'));
        self::assertNotNull($this->store->findRunBySlot($runB->scheduleId, 'prune-tenant-b', 'tenant-b'));
    }

    final public function test_prune_with_exclude_tenant_ids_skips_excluded_tenants(): void
    {
        $past = new DateTimeImmutable('2020-01-01T00:00:00Z');

        $runA = $this->makeRun(ScheduleId::generate(), 'prune-excl-a', 'tenant-a', $past);
        $runB = $this->makeRun(ScheduleId::generate(), 'prune-excl-b', 'tenant-b', $past);
        $this->store->insertRun($runA);
        $this->store->insertRun($runB);
        $this->store->transitionRunState($runA->runId, RunState::Completed, $past);
        $this->store->transitionRunState($runB->runId, RunState::Completed, $past);

        $result = $this->store->pruneOldRuns(
            new DateTimeImmutable('2021-01-01T00:00:00Z'),
            null,
            ['tenant-a'],
        );

        self::assertSame(1, $result->deletedCount);
        self::assertNotNull($this->store->findRunBySlot($runA->scheduleId, 'prune-excl-a', 'tenant-a'));
        self::assertNull($this->store->findRunBySlot($runB->scheduleId, 'prune-excl-b', 'tenant-b'));
    }

    final public function test_prune_rejects_both_tenant_id_and_exclude_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->pruneOldRuns(new DateTimeImmutable('2021-01-01T00:00:00Z'), 'tenant-a', ['tenant-b']);
    }

    // ─────────────────────────────────────────────────────────────
    // Group F — findRunBySlot
    // ─────────────────────────────────────────────────────────────

    final public function test_find_run_by_slot_returns_full_record(): void
    {
        $id  = ScheduleId::generate();
        $at  = new DateTimeImmutable('2026-07-01T09:00:00Z');
        $run = $this->makeRun($id, 'frbs-slot', 'ta', $at);

        $this->store->insertRun($run);

        $found = $this->store->findRunBySlot($id, 'frbs-slot', 'ta');

        self::assertNotNull($found);
        self::assertSame($run->runId, $found->runId);
        self::assertSame('frbs-slot', $found->slot);
        self::assertSame(RunState::Dispatched, $found->state);
        self::assertSame(1, $found->attempt);
    }

    final public function test_find_run_by_slot_returns_null_for_unknown_slot(): void
    {
        self::assertNull($this->store->findRunBySlot(ScheduleId::generate(), 'no-such-slot', 'ta'));
    }

    final public function test_find_run_by_slot_tenant_isolation(): void
    {
        $id  = ScheduleId::generate();
        $run = $this->makeRun($id, 'iso-frbs', 'ta');

        $this->store->insertRun($run);

        // Wrong tenant — must return null
        self::assertNull($this->store->findRunBySlot($id, 'iso-frbs', 'tb'));
    }

    final public function test_find_run_by_slot_reflects_post_transition_state(): void
    {
        $id  = ScheduleId::generate();
        $run = $this->makeRun($id, 'trans-frbs', 'ta');

        $this->store->insertRun($run);
        $this->store->transitionRunState($run->runId, RunState::Completed, new DateTimeImmutable());

        $found = $this->store->findRunBySlot($id, 'trans-frbs', 'ta');

        self::assertNotNull($found);
        self::assertSame(RunState::Completed, $found->state);
    }

    final public function test_find_run_by_slot_daemon_mode_null_sees_all(): void
    {
        $id  = ScheduleId::generate();
        $run = $this->makeRun($id, 'daemon-frbs', 'ta');

        $this->store->insertRun($run);

        $found = $this->store->findRunBySlot($id, 'daemon-frbs', null);

        self::assertNotNull($found);
        self::assertSame($run->runId, $found->runId);
    }

    // ─────────────────────────────────────────────────────────────
    // Group G — findLastDispatchTimes (dead-man bulk query)
    // ─────────────────────────────────────────────────────────────

    final public function test_find_last_dispatch_times_empty_input_returns_empty(): void
    {
        self::assertSame([], $this->store->findLastDispatchTimes([], null));
    }

    final public function test_find_last_dispatch_times_never_fired_returns_null_for_id(): void
    {
        $id     = ScheduleId::generate();
        $result = $this->store->findLastDispatchTimes([$id], null);

        self::assertArrayHasKey($id->toString(), $result);
        self::assertNull($result[$id->toString()]);
    }

    final public function test_find_last_dispatch_times_returns_max_dispatched_at(): void
    {
        $id    = ScheduleId::generate();
        $early = new DateTimeImmutable('2026-07-01T09:00:00Z');
        $late  = new DateTimeImmutable('2026-07-01T10:00:00Z');

        $this->store->insertRun($this->makeRun($id, 'slot-early', null, $early));
        $this->store->insertRun($this->makeRun($id, 'slot-late',  null, $late));

        $result = $this->store->findLastDispatchTimes([$id], null);

        self::assertNotNull($result[$id->toString()]);
        self::assertGreaterThanOrEqual(
            $late->getTimestamp(),
            $result[$id->toString()]->getTimestamp(),
        );
    }

    final public function test_find_last_dispatch_times_bulk_multiple_schedules(): void
    {
        $idA = ScheduleId::generate();
        $idB = ScheduleId::generate();
        $at  = new DateTimeImmutable('2026-07-01T10:00:00Z');

        $this->store->insertRun($this->makeRun($idA, 'gslot-a', null, $at));
        $this->store->insertRun($this->makeRun($idB, 'gslot-b', null, $at));

        $result = $this->store->findLastDispatchTimes([$idA, $idB], null);

        self::assertArrayHasKey($idA->toString(), $result);
        self::assertArrayHasKey($idB->toString(), $result);
        self::assertNotNull($result[$idA->toString()]);
        self::assertNotNull($result[$idB->toString()]);
    }

    final public function test_find_last_dispatch_times_unknown_id_mapped_to_null(): void
    {
        $known   = ScheduleId::generate();
        $unknown = ScheduleId::generate();
        $at      = new DateTimeImmutable('2026-07-01T10:00:00Z');

        $this->store->insertRun($this->makeRun($known, 'gslot-known', null, $at));

        $result = $this->store->findLastDispatchTimes([$known, $unknown], null);

        self::assertNotNull($result[$known->toString()]);
        self::assertNull($result[$unknown->toString()]);
    }

    final public function test_find_last_dispatch_times_daemon_null_tenant_sees_all(): void
    {
        $id = ScheduleId::generate();
        $at = new DateTimeImmutable('2026-07-01T10:00:00Z');

        $this->store->insertRun($this->makeRun($id, 'gslot-ta', 'ta', $at));

        $result = $this->store->findLastDispatchTimes([$id], null);

        self::assertNotNull($result[$id->toString()]);
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    private function makeRun(
        ScheduleId        $scheduleId,
        string            $slot,
        ?string           $tenantId,
        ?DateTimeImmutable $dispatchedAt = null,
    ): ScheduleRun {
        $at     = $dispatchedAt ?? new DateTimeImmutable('2026-07-01T00:00:00Z');
        // Include tenantId so cross-tenant runs for the same schedule+slot get distinct PKs
        $runKey = IdempotencyKey::fromSlotKey(($tenantId ?? 'system') . ':' . $scheduleId->toString() . ':' . $slot);

        return new ScheduleRun(
            runId:        $runKey->value,
            scheduleId:   $scheduleId,
            tenantId:     $tenantId,
            slot:         $slot,
            scheduledFor: $at,
            dispatchedAt: $at,
            state:        RunState::Dispatched,
        );
    }
}
