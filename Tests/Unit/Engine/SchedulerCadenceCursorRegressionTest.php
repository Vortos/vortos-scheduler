<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Engine;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Engine\DueScan;
use Vortos\Scheduler\Engine\MisfireResolver;
use Vortos\Scheduler\Engine\SchedulerDaemon;
use Vortos\Scheduler\Engine\SlotCalculator;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Lease\Driver\InMemoryLeaseStore;
use Vortos\Scheduler\Registry\ScheduleResolver;
use Vortos\Scheduler\Registry\StaticScheduleRegistry;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;
use Vortos\Scheduler\Testing\FakeFireDispatcherPort;
use Vortos\Scheduler\Testing\InMemoryScheduleCursorStore;
use Vortos\Scheduler\Testing\InMemoryScheduleStatusOverrideStore;
use Vortos\Scheduler\Testing\InMemoryScheduleStore;

/**
 * Regression guards for the three cadence defects fixed by the cadence-cursor model. Each of these
 * would deadlock or corrupt cadence under the old slot-key-parsing scheme.
 */
final class SchedulerCadenceCursorRegressionTest extends TestCase
{
    private MutableClock                $clock;
    private InMemoryScheduleStore       $store;
    private InMemoryScheduleCursorStore $cursors;
    private FakeFireDispatcherPort      $dispatcher;

    protected function setUp(): void
    {
        $this->clock      = new MutableClock(new DateTimeImmutable('2026-07-01T10:00:00Z', new DateTimeZone('UTC')));
        $this->store      = new InMemoryScheduleStore();
        $this->cursors    = new InMemoryScheduleCursorStore();
        $this->dispatcher = new FakeFireDispatcherPort();
    }

    // ── Bug A: SkipMissed + short cadence + never-scanned = permanent no-fire ──────

    public function test_bug_a_skip_missed_short_cadence_never_scanned_does_not_deadlock(): void
    {
        // @every 60s under SkipMissed, never scanned. Under the old model the cursor seeded to the
        // 24h horizon → ~1440 "missed" slots → SkipMissed skipped all → anchor never advanced →
        // deadlock forever. Under the fix it anchors to now and fires on the next tick.
        $schedule = $this->makeSchedule(new IntervalTrigger(60), MisfirePolicy::skipMissed());
        $this->store->seed($schedule);
        $daemon = $this->makeDaemon();

        // Tick 1: anchored to now → nothing due yet, no fire, but the cursor is established.
        $daemon->runOnce();
        self::assertSame(0, $this->dispatcher->callCount(), 'first tick anchors, does not backfill 24h');

        // One cadence interval later a single slot is due → it fires (no deadlock).
        $this->clock->advanceSeconds(60);
        $daemon->runOnce();
        self::assertGreaterThan(0, $this->dispatcher->callCount(), 'schedule must fire once anchored — not deadlock');
    }

    // ── Bug C: SkipMissed cannot advance the anchor when it skips = runaway ────────

    public function test_bug_c_skip_missed_advances_cursor_even_when_it_skips(): void
    {
        // Seed a cursor 3 intervals back so 3 slots are due at once → SkipMissed skips all. The
        // cursor MUST still advance to now, or every subsequent tick sees an ever-growing backlog
        // and the schedule is wedged forever.
        $schedule = $this->makeSchedule(new IntervalTrigger(60), MisfirePolicy::skipMissed());
        $this->store->seed($schedule);
        $this->cursors->seed($schedule->id, $schedule->tenantId, $this->clock->now()->modify('-180 seconds'));
        $daemon = $this->makeDaemon();

        $daemon->runOnce();

        self::assertSame(0, $this->dispatcher->callCount(), 'SkipMissed skips a 3-slot backlog');
        $cursor = $this->cursors->findCursors([$schedule->id], null)[$schedule->id->toString()];
        self::assertEquals($this->clock->now(), $cursor->cursorAt, 'cursor must advance to now despite skipping');

        // Next normal tick fires exactly once — proving no runaway.
        $this->clock->advanceSeconds(60);
        $daemon->runOnce();
        self::assertSame(1, $this->dispatcher->callCount());
    }

    // ── Cursor advancement persistence + CAS ──────────────────────────────────────

    public function test_dispatched_fire_advances_and_versions_the_cursor(): void
    {
        $schedule = $this->makeSchedule(new IntervalTrigger(3600), MisfirePolicy::skipMissed());
        $this->store->seed($schedule);
        $this->cursors->seed($schedule->id, $schedule->tenantId, $this->clock->now()->modify('-3600 seconds'), version: 1);
        $daemon = $this->makeDaemon();

        $daemon->runOnce();

        self::assertSame(1, $this->dispatcher->callCount());
        $cursor = $this->cursors->findCursors([$schedule->id], null)[$schedule->id->toString()];
        self::assertEquals($this->clock->now(), $cursor->cursorAt);
        self::assertSame(2, $cursor->version, 'a settled dispatch CAS-advances the cursor version');
    }

    // ── FB-61: a cursor already at its target is not rewritten ───────────────────

    public function test_a_cursor_already_at_its_target_is_not_rewritten_every_tick(): void
    {
        // Daily cadence, nothing due. Before the guard every tick bumped the version and updated_at
        // of every schedule in the shard, so the database was never idle for a full minute.
        $schedule = $this->makeSchedule(new IntervalTrigger(86400), MisfirePolicy::skipMissed());
        $this->store->seed($schedule);
        $daemon = $this->makeDaemon();

        $daemon->runOnce(); // anchors the cursor — one legitimate write
        self::assertSame(1, $this->cursors->writes);

        for ($i = 0; $i < 10; $i++) {
            $this->clock->advanceSeconds(60);
            $daemon->runOnce();
        }

        $cursor = $this->cursors->findCursors([$schedule->id], null)[$schedule->id->toString()];
        self::assertSame(0, $this->dispatcher->callCount());
        self::assertSame(1, $cursor->version, 'an unchanged cursor must not be CAS-bumped');
        self::assertSame(1, $this->cursors->writes, 'ten idle ticks must write nothing');
    }

    public function test_an_unchanged_cursor_with_no_first_seen_instant_is_still_written_to_backfill_it(): void
    {
        // Rows predating first_seen_at hold NULL, and the advance is the only thing that fills it.
        // Skipping them as no-ops would leave the dead-man check unable to judge them for ever.
        $schedule = $this->makeSchedule(new IntervalTrigger(86400), MisfirePolicy::skipMissed());
        $this->store->seed($schedule);
        $daemon = $this->makeDaemon();
        $daemon->runOnce();
        $anchored = $this->cursors->findCursors([$schedule->id], null)[$schedule->id->toString()];
        $this->cursors->seed($schedule->id, null, $anchored->cursorAt, $anchored->version, firstSeenAt: null);
        $this->cursors->writes = 0;

        $daemon->runOnce();

        $cursor = $this->cursors->findCursors([$schedule->id], null)[$schedule->id->toString()];
        self::assertNotNull($cursor->firstSeenAt);
        self::assertSame(1, $this->cursors->writes);

        $daemon->runOnce();
        self::assertSame(1, $this->cursors->writes, 'once backfilled, it is skipped like any other no-op');
    }

    public function test_a_cursor_that_moves_is_still_advanced(): void
    {
        $schedule = $this->makeSchedule(new IntervalTrigger(60), MisfirePolicy::skipMissed());
        $this->store->seed($schedule);
        $daemon = $this->makeDaemon();
        $daemon->runOnce();

        $this->clock->advanceSeconds(60);
        $daemon->runOnce();

        $cursor = $this->cursors->findCursors([$schedule->id], null)[$schedule->id->toString()];
        self::assertSame(1, $this->dispatcher->callCount());
        self::assertEquals($this->clock->now(), $cursor->cursorAt);
        self::assertSame(2, $cursor->version);
    }

    // ── Bug B: cadence is decoupled from the execution log (run-now cannot poison it) ─

    public function test_bug_b_cadence_derives_only_from_cursor_store_not_runs(): void
    {
        // The daemon has no run-store dependency at all: cadence is computed purely from the cursor
        // store. A manual run-now writes a `manual:<iso>:<hash>` row to scheduler_runs, which the
        // daemon never reads — so it can no longer be mis-parsed into a poisoned cadence anchor.
        // Here the cursor alone dictates the due slot; there is no code path from runs to cadence.
        $schedule = $this->makeSchedule(new IntervalTrigger(3600), MisfirePolicy::fireOnceNow());
        $this->store->seed($schedule);
        $this->cursors->seed($schedule->id, $schedule->tenantId, $this->clock->now()->modify('-3600 seconds'));
        $daemon = $this->makeDaemon();

        $daemon->runOnce();

        self::assertSame(1, $this->dispatcher->callCount());
        $fire = $this->dispatcher->lastFire();
        self::assertNotNull($fire);
        // The fired slot is a normal cadence slot, never a manual: slot.
        self::assertStringNotContainsString('manual:', $fire->slot);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────────

    private function makeDaemon(): SchedulerDaemon
    {
        $resolver = new ScheduleResolver(
            new StaticScheduleRegistry([]),
            $this->store,
            new InMemoryScheduleStatusOverrideStore(),
        );

        return new SchedulerDaemon(
            leasePort:                new InMemoryLeaseStore($this->clock),
            scheduleResolver:         $resolver,
            cursorStore:              $this->cursors,
            dueScan:                  new DueScan(new MisfireResolver(new SlotCalculator()), 86400),
            fireDispatcher:           $this->dispatcher,
            clock:                    $this->clock,
            logger:                   new NullLogger(),
            shardCount:               1,
            leaseTtlSec:              30,
            maxIdleSec:               60,
            tenantMaxConcurrentFires: 0,
        );
    }

    private function makeSchedule(IntervalTrigger $trigger, MisfirePolicy $misfire): Schedule
    {
        return new Schedule(
            id:       ScheduleId::generate(),
            name:     'cadence-regression',
            source:   ScheduleSource::Dynamic,
            trigger:  $trigger,
            command:  new CommandSpec('FakeRegressionCommand'),
            misfire:  $misfire,
            overlap:  OverlapPolicy::AllowConcurrent,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,
        );
    }
}
