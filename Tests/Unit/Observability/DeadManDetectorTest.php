<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Observability;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Vortos\Alerts\AlertDispatcherInterface;
use Vortos\Alerts\Dedupe\DedupeDecision;
use Vortos\Alerts\DispatchResult;
use Vortos\Alerts\Event\AlertEvent;
use Vortos\Alerts\Event\AlertSource;
use Vortos\Alerts\Severity;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Observability\DeadManDetector;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\RecurringTrigger;
use Vortos\Scheduler\Schedule\Trigger\Trigger;
use Vortos\Scheduler\Store\CadenceCursor;
use Vortos\Scheduler\Store\PruneResult;
use Vortos\Scheduler\Store\ScheduleCursorStoreInterface;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;
use Vortos\Scheduler\Tests\Unit\Security\Support\StubAllowlistedCommand;

final class DeadManDetectorTest extends TestCase
{
    private const ENV = 'testing';
    private const TOLERANCE = 600;

    private DateTimeImmutable $now;
    private MutableClock $clock;

    protected function setUp(): void
    {
        $this->now   = new DateTimeImmutable('2026-07-01T10:00:00+00:00');
        $this->clock = new MutableClock($this->now);
    }

    // ── Edge cases ────────────────────────────────────────────────────────────

    public function test_empty_schedules_does_nothing(): void
    {
        $dispatcher = new SpyAlertDispatcher();
        $detector   = $this->makeDetector($dispatcher, []);

        $detector->check([]);

        self::assertCount(0, $dispatcher->dispatched());
    }

    public function test_paused_schedule_is_skipped(): void
    {
        $dispatcher = new SpyAlertDispatcher();
        $detector   = $this->makeDetector($dispatcher, []);
        $schedule   = $this->makeSchedule(status: ScheduleStatus::Paused);

        $detector->check([$schedule]);

        self::assertCount(0, $dispatcher->dispatched());
    }

    public function test_opted_out_schedule_is_skipped(): void
    {
        $dispatcher = new SpyAlertDispatcher();
        $detector   = $this->makeDetector($dispatcher, []);
        $schedule   = $this->makeSchedule(metadata: ['deadman_enabled' => 'false']);

        $detector->check([$schedule]);

        self::assertCount(0, $dispatcher->dispatched());
    }

    public function test_schedule_not_yet_due_in_window_does_not_alert(): void
    {
        // nextRunAfter(windowStart) returns a time AFTER now → schedule not yet due
        $windowStart = $this->now->modify(sprintf('-%d seconds', self::TOLERANCE));
        $trigger = $this->makeTrigger(function (DateTimeImmutable $after) use ($windowStart) {
            // Auto-bump calls: return null so auto-bump is bypassed
            if ($after->getTimestamp() >= $this->now->getTimestamp()) {
                return null;
            }
            // windowStart call — return now + 100s (in the future relative to now)
            return $this->now->modify('+100 seconds');
        });

        $dispatcher = new SpyAlertDispatcher();
        $detector   = $this->makeDetector($dispatcher, []);
        $schedule   = $this->makeSchedule(trigger: $trigger);

        $detector->check([$schedule]);

        self::assertCount(0, $dispatcher->dispatched());
    }

    // ── Healthy schedules must be silent ──────────────────────────────────────

    /**
     * A working schedule produces silence — the question this suite never asked.
     *
     * Every pre-existing case drove the detector with a stub trigger written to look overdue, so it
     * only ever established "it alerts when told to". This one uses a real daily cron with the
     * shipped default tolerance and no per-schedule override.
     *
     * In fairness to the old code, this exact case passed there too: a schedule with dispatch
     * history inside a two-year window looks healthy however badly the window is sized. The bugs
     * lived either side of it — a schedule with NO history yet
     * ({@see test_a_newly_registered_schedule_is_silent}) and a schedule that genuinely stopped
     * ({@see test_a_daily_schedule_that_stopped_days_ago_pages}), both of which the old code got
     * wrong. This test is the baseline the other two are read against.
     */
    public function test_a_healthy_daily_schedule_is_silent(): void
    {
        $dispatcher = new SpyAlertDispatcher();
        $schedule   = $this->makeSchedule(name: 'applicant-notifications-full-sweep', trigger: $this->makeDailyTrigger('00:10'));

        // Fired on schedule this morning, and first seen weeks ago.
        $detector = $this->makeDetector(
            $dispatcher,
            [$schedule->id->toString() => $this->now->modify('-9 hours 50 minutes')],
            [$schedule->id->toString() => $this->now->modify('-21 days')],
        );

        $detector->check([$schedule]);

        self::assertSame([], $dispatcher->dispatched());
    }

    /** The same schedule, actually dead: silence for days must still page. */
    public function test_a_daily_schedule_that_stopped_days_ago_pages(): void
    {
        $dispatcher = new SpyAlertDispatcher();
        $schedule   = $this->makeSchedule(name: 'applicant-notifications-full-sweep', trigger: $this->makeDailyTrigger('00:10'));

        $detector = $this->makeDetector(
            $dispatcher,
            [$schedule->id->toString() => $this->now->modify('-6 days')],
            [$schedule->id->toString() => $this->now->modify('-21 days')],
        );

        $detector->check([$schedule]);

        self::assertCount(1, $dispatcher->dispatched());
        self::assertSame(Severity::Critical, $dispatcher->dispatched()[0]->severity);
        self::assertSame('overdue', $dispatcher->dispatched()[0]->labels['verdict']);
    }

    /**
     * The tolerance a daily schedule gets must be about two days, not two years.
     *
     * The old period calculation measured `nextRunAfter(now) - nextRunAfter(now - 1 year)` and got
     * ~1 year for everything, so every schedule was handed a ~730-day window — long enough that a
     * dead daily job would not have paged until 2028. The assertion is on the tolerance the alert
     * reports, because that number is what was visibly wrong in production.
     */
    public function test_a_daily_schedule_gets_a_two_day_tolerance_not_two_years(): void
    {
        $dispatcher = new SpyAlertDispatcher();
        $schedule   = $this->makeSchedule(trigger: $this->makeDailyTrigger('00:10'));

        $detector = $this->makeDetector(
            $dispatcher,
            [$schedule->id->toString() => $this->now->modify('-30 days')],
            [$schedule->id->toString() => $this->now->modify('-60 days')],
        );

        $detector->check([$schedule]);

        self::assertCount(1, $dispatcher->dispatched());
        self::assertSame('172800', $dispatcher->dispatched()[0]->annotations['tolerance_sec']);
    }

    /**
     * A weekday-only cron must be sized off its longest gap, not its shortest.
     *
     * Sizing off Tuesday-to-Wednesday gives a two-day window, and the weekend is three days wide —
     * so the schedule would page every Monday morning having done nothing wrong.
     */
    public function test_an_unevenly_spaced_schedule_is_sized_off_its_longest_gap(): void
    {
        $dispatcher = new SpyAlertDispatcher();
        // Fires 09:00 on weekdays only; "now" is a Wednesday, so the sampled slots span a weekend.
        $schedule = $this->makeSchedule(trigger: $this->makeWeekdayTrigger('09:00'));

        $detector = $this->makeDetector(
            $dispatcher,
            [$schedule->id->toString() => $this->now->modify('-30 days')],
            [$schedule->id->toString() => $this->now->modify('-60 days')],
        );

        $detector->check([$schedule]);

        self::assertCount(1, $dispatcher->dispatched());
        self::assertSame(
            (string) (3 * 86400 * 2),
            $dispatcher->dispatched()[0]->annotations['tolerance_sec'],
            'The window must cover the weekend gap, doubled — not the weekday gap.',
        );
    }

    // ── Alert-raising scenarios ───────────────────────────────────────────────

    /**
     * A schedule with no runs that has been known for longer than its tolerance really has never
     * fired, and that is an outage.
     *
     * The first-seen baseline is what makes this callable at all. Without it, "no runs" is the same
     * observation for a schedule registered a minute ago, and calling that dead is what produced
     * false Criticals for every schedule the last two deployments introduced.
     */
    public function test_schedule_that_has_never_fired_since_first_seen_raises_critical(): void
    {
        $trigger    = $this->makePastDueTrigger();
        $dispatcher = new SpyAlertDispatcher();
        $schedule   = $this->makeSchedule(trigger: $trigger, metadata: ['deadman_tolerance_sec' => (string) self::TOLERANCE]);

        // Known about for far longer than the tolerance window, and still nothing in the ledger.
        $detector = $this->makeDetector($dispatcher, [], [
            $schedule->id->toString() => $this->now->modify('-30 days'),
        ]);

        $detector->check([$schedule]);

        self::assertCount(1, $dispatcher->dispatched());
        self::assertSame(Severity::Critical, $dispatcher->dispatched()[0]->severity);
        self::assertSame('never_fired', $dispatcher->dispatched()[0]->labels['verdict']);
    }

    /** No baseline to judge against is a monitoring gap, and says so — as a Warning, not a page. */
    public function test_schedule_with_no_first_seen_baseline_is_indeterminate(): void
    {
        $dispatcher = new SpyAlertDispatcher();
        $schedule   = $this->makeSchedule(
            trigger:  $this->makePastDueTrigger(),
            metadata: ['deadman_tolerance_sec' => (string) self::TOLERANCE],
        );

        $this->makeDetector($dispatcher, [])->check([$schedule]);

        self::assertCount(1, $dispatcher->dispatched());
        self::assertSame(Severity::Warning, $dispatcher->dispatched()[0]->severity);
        self::assertSame('indeterminate', $dispatcher->dispatched()[0]->labels['verdict']);
    }

    /**
     * The false positive that started this. A schedule registered moments ago has no runs and is
     * completely healthy; it must not alert, at any severity.
     */
    public function test_a_newly_registered_schedule_is_silent(): void
    {
        $dispatcher = new SpyAlertDispatcher();
        $schedule   = $this->makeSchedule(
            trigger:  $this->makePastDueTrigger(),
            metadata: ['deadman_tolerance_sec' => (string) self::TOLERANCE],
        );

        $detector = $this->makeDetector($dispatcher, [], [
            $schedule->id->toString() => $this->now->modify('-60 seconds'),
        ]);

        $detector->check([$schedule]);

        self::assertSame([], $dispatcher->dispatched());
    }

    /** An unreadable run ledger leaves every schedule unmonitored, and must not pass as silence. */
    public function test_unreadable_run_ledger_raises_one_warning(): void
    {
        $runStore = new class implements ScheduleRunStoreInterface {
            public function findLastDispatchTimes(array $scheduleIds, ?string $tenantId): array
            {
                throw new \RuntimeException('DB gone');
            }

            public function insertRun(\Vortos\Scheduler\Fire\ScheduleRun $run): void {}
            public function findLastSlots(array $scheduleIds, ?string $tenantId): array { return []; }
            public function findRunState(\Vortos\Scheduler\Schedule\ScheduleId $scheduleId, string $slot, ?string $tenantId): ?\Vortos\Scheduler\Fire\RunState { return null; }
            public function findRunBySlot(\Vortos\Scheduler\Schedule\ScheduleId $scheduleId, string $slot, ?string $tenantId): ?\Vortos\Scheduler\Fire\ScheduleRun { return null; }
            public function transitionRunState(string $runId, \Vortos\Scheduler\Fire\RunState $newState, \DateTimeImmutable $at): void {}
            public function pruneOldRuns(\DateTimeImmutable $olderThan, ?string $tenantId = null, array $excludeTenantIds = []): PruneResult { return new PruneResult(0, false); }
        };

        $dispatcher = new SpyAlertDispatcher();
        $detector   = new DeadManDetector($runStore, $dispatcher, $this->clock, self::ENV, self::TOLERANCE);

        $detector->check([
            $this->makeSchedule(trigger: $this->makePastDueTrigger()),
            $this->makeSchedule(trigger: $this->makePastDueTrigger()),
        ]);

        self::assertCount(1, $dispatcher->dispatched(), 'One alert for the node, not one per schedule.');
        self::assertSame(Severity::Warning, $dispatcher->dispatched()[0]->severity);
        self::assertStringContainsString('cannot read dispatch history', $dispatcher->dispatched()[0]->title);
    }

    public function test_alert_source_is_scheduler(): void
    {
        $dispatcher = new SpyAlertDispatcher();
        $schedule   = $this->makeSchedule(trigger: $this->makePastDueTrigger(), metadata: ['deadman_tolerance_sec' => (string) self::TOLERANCE]);

        $this->makeDetector($dispatcher, [])->check([$schedule]);

        self::assertSame(AlertSource::Scheduler, $dispatcher->dispatched()[0]->source);
    }

    public function test_alert_title_contains_schedule_name(): void
    {
        $dispatcher = new SpyAlertDispatcher();
        $schedule   = $this->makeSchedule(name: 'nightly-report', trigger: $this->makePastDueTrigger(), metadata: ['deadman_tolerance_sec' => (string) self::TOLERANCE]);

        $this->makeDetector($dispatcher, [])->check([$schedule]);

        self::assertStringContainsString('nightly-report', $dispatcher->dispatched()[0]->title);
    }

    public function test_alert_carries_schedule_id_label(): void
    {
        $scheduleId = ScheduleId::generate();
        $dispatcher = new SpyAlertDispatcher();
        $schedule   = $this->makeSchedule(id: $scheduleId, trigger: $this->makePastDueTrigger(), metadata: ['deadman_tolerance_sec' => (string) self::TOLERANCE]);

        $this->makeDetector($dispatcher, [])->check([$schedule]);

        self::assertSame($scheduleId->toString(), $dispatcher->dispatched()[0]->labels['schedule_id']);
    }

    public function test_alert_carries_tenant_id(): void
    {
        $dispatcher = new SpyAlertDispatcher();
        $schedule   = $this->makeSchedule(
            trigger:  $this->makePastDueTrigger(),
            tenantId: 'tenant-99',
            metadata: ['deadman_tolerance_sec' => (string) self::TOLERANCE],
        );

        $this->makeDetector($dispatcher, [])->check([$schedule]);

        self::assertSame('tenant-99', $dispatcher->dispatched()[0]->tenantId);
    }

    // ── No-alert when dispatched in window ───────────────────────────────────

    public function test_schedule_dispatched_within_window_no_alert(): void
    {
        $windowStart = $this->now->modify(sprintf('-%d seconds', self::TOLERANCE));
        $lastDispatch = $windowStart->modify('+60 seconds'); // inside window

        $dispatcher = new SpyAlertDispatcher();
        $schedule   = $this->makeSchedule(trigger: $this->makePastDueTrigger(), metadata: ['deadman_tolerance_sec' => (string) self::TOLERANCE]);
        $detector   = $this->makeDetector($dispatcher, [$schedule->id->toString() => $lastDispatch]);

        $detector->check([$schedule]);

        self::assertCount(0, $dispatcher->dispatched());
    }

    public function test_schedule_dispatched_exactly_at_window_start_no_alert(): void
    {
        $windowStart  = $this->now->modify(sprintf('-%d seconds', self::TOLERANCE));
        $lastDispatch = $windowStart; // exactly at window start

        $dispatcher = new SpyAlertDispatcher();
        $schedule   = $this->makeSchedule(trigger: $this->makePastDueTrigger(), metadata: ['deadman_tolerance_sec' => (string) self::TOLERANCE]);
        $detector   = $this->makeDetector($dispatcher, [$schedule->id->toString() => $lastDispatch]);

        $detector->check([$schedule]);

        self::assertCount(0, $dispatcher->dispatched());
    }

    // ── Alert when last dispatch is outside window ────────────────────────────

    public function test_schedule_dispatched_before_window_raises_alert(): void
    {
        $windowStart  = $this->now->modify(sprintf('-%d seconds', self::TOLERANCE));
        $lastDispatch = $windowStart->modify('-1 second'); // 1 second before window

        $dispatcher = new SpyAlertDispatcher();
        $schedule   = $this->makeSchedule(trigger: $this->makePastDueTrigger(), metadata: ['deadman_tolerance_sec' => (string) self::TOLERANCE]);
        $detector   = $this->makeDetector($dispatcher, [$schedule->id->toString() => $lastDispatch]);

        $detector->check([$schedule]);

        self::assertCount(1, $dispatcher->dispatched());
    }

    // ── Custom routing override ───────────────────────────────────────────────

    public function test_alert_routing_override_applied(): void
    {
        $dispatcher = new SpyAlertDispatcher();
        $schedule   = $this->makeSchedule(
            trigger:  $this->makePastDueTrigger(),
            metadata: [
                'deadman_tolerance_sec'   => (string) self::TOLERANCE,
                'deadman_alert_routing'   => 'pagerduty,slack',
            ],
        );

        $this->makeDetector($dispatcher, [])->check([$schedule]);

        self::assertNotNull($dispatcher->lastRoutingOverride);
        self::assertContains('pagerduty', $dispatcher->lastRoutingOverride);
        self::assertContains('slack', $dispatcher->lastRoutingOverride);
    }

    // ── Explicit tolerance from metadata ─────────────────────────────────────

    public function test_explicit_tolerance_via_metadata(): void
    {
        // Use a 3600s tolerance — schedule dispatched 1801s ago (within 3600s window)
        $lastDispatch = $this->now->modify('-1801 seconds');
        $dispatcher   = new SpyAlertDispatcher();
        $schedule     = $this->makeSchedule(
            trigger:  $this->makePastDueTrigger(),
            metadata: ['deadman_tolerance_sec' => '3600'],
        );
        $detector = $this->makeDetector($dispatcher, [$schedule->id->toString() => $lastDispatch]);

        $detector->check([$schedule]);

        // 1801s ago is within the 3600s window → no alert
        self::assertCount(0, $dispatcher->dispatched());
    }

    // ── Safety: never throws ─────────────────────────────────────────────────

    public function test_run_store_query_failure_is_swallowed(): void
    {
        $runStore = new class implements ScheduleRunStoreInterface {
            public function findLastDispatchTimes(array $scheduleIds, ?string $tenantId): array
            {
                throw new \RuntimeException('DB gone');
            }

            // Required stubs
            public function insertRun(\Vortos\Scheduler\Fire\ScheduleRun $run): void {}
            public function findLastSlots(array $scheduleIds, ?string $tenantId): array { return []; }
            public function findRunState(\Vortos\Scheduler\Schedule\ScheduleId $scheduleId, string $slot, ?string $tenantId): ?\Vortos\Scheduler\Fire\RunState { return null; }
            public function findRunBySlot(\Vortos\Scheduler\Schedule\ScheduleId $scheduleId, string $slot, ?string $tenantId): ?\Vortos\Scheduler\Fire\ScheduleRun { return null; }
            public function transitionRunState(string $runId, \Vortos\Scheduler\Fire\RunState $newState, \DateTimeImmutable $at): void {}
            public function pruneOldRuns(\DateTimeImmutable $olderThan, ?string $tenantId = null, array $excludeTenantIds = []): PruneResult { return new PruneResult(0, false); }
        };

        $dispatcher = new SpyAlertDispatcher();
        $schedule   = $this->makeSchedule(trigger: $this->makePastDueTrigger(), metadata: ['deadman_tolerance_sec' => (string) self::TOLERANCE]);
        $detector   = new DeadManDetector($runStore, $dispatcher, $this->clock, self::ENV, self::TOLERANCE);

        // Must NOT throw
        $detector->check([$schedule]);

        // The failure is now reported rather than swallowed into silence — see
        // test_unreadable_run_ledger_raises_one_warning. What matters here is that the daemon tick
        // survives it.
        self::assertCount(1, $dispatcher->dispatched());
    }

    public function test_broken_alert_dispatcher_is_swallowed(): void
    {
        $broken = new class implements AlertDispatcherInterface {
            public function dispatch(AlertEvent $event, ?array $routingOverride = null): DispatchResult
            {
                throw new \RuntimeException('alert backend down');
            }
        };

        $schedule = $this->makeSchedule(trigger: $this->makePastDueTrigger(), metadata: ['deadman_tolerance_sec' => (string) self::TOLERANCE]);
        $detector = $this->makeDetector($broken, []);

        // Must NOT throw
        $detector->check([$schedule]);

        $this->addToAssertionCount(1);
    }

    public function test_multiple_schedules_one_failing_continues_others(): void
    {
        $badTrigger = $this->makeTrigger(function (\DateTimeImmutable $after) {
            if ($after->getTimestamp() < $this->now->getTimestamp()) {
                // windowStart call
                throw new \RuntimeException('trigger explodes');
            }

            return null;
        });

        $goodTrigger = $this->makePastDueTrigger();

        $dispatcher = new SpyAlertDispatcher();
        $badSchedule  = $this->makeSchedule(trigger: $badTrigger, metadata: ['deadman_tolerance_sec' => (string) self::TOLERANCE]);
        $goodSchedule = $this->makeSchedule(trigger: $goodTrigger, metadata: ['deadman_tolerance_sec' => (string) self::TOLERANCE]);

        $this->makeDetector($dispatcher, [])->check([$badSchedule, $goodSchedule]);

        // Good schedule still raised an alert despite bad one exploding
        self::assertCount(1, $dispatcher->dispatched());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makePastDueTrigger(): Trigger
    {
        return $this->makeTrigger(function (DateTimeImmutable $after) {
            // If called with now or later → auto-bump bypass (return null)
            if ($after->getTimestamp() >= $this->now->getTimestamp()) {
                return null;
            }
            // Window-start call → return a time 300s after after (well before now)
            return $after->modify('+300 seconds');
        });
    }

    /**
     * Real cron triggers, not stubs.
     *
     * The stubbed triggers below exist to force specific branches, but they also mean the period
     * calculation was only ever exercised against a closure written to satisfy it. The defect being
     * fixed here lived entirely in that calculation, so the cases that matter drive the actual cron
     * implementation the daemon uses.
     */
    private function makeDailyTrigger(string $hhmm): Trigger
    {
        [$hour, $minute] = array_map('intval', explode(':', $hhmm));

        return new RecurringTrigger(sprintf('%d %d * * *', $minute, $hour), new DateTimeZone('UTC'));
    }

    private function makeWeekdayTrigger(string $hhmm): Trigger
    {
        [$hour, $minute] = array_map('intval', explode(':', $hhmm));

        return new RecurringTrigger(sprintf('%d %d * * 1-5', $minute, $hour), new DateTimeZone('UTC'));
    }

    private function makeTrigger(\Closure $resolver): Trigger
    {
        return new class($resolver) implements Trigger {
            public function __construct(private \Closure $resolver) {}

            public function nextRunAfter(DateTimeImmutable $after): ?DateTimeImmutable
            {
                return ($this->resolver)($after);
            }

            public function describe(): string { return 'stub'; }
        };
    }

    /**
     * @param array<string, DateTimeImmutable>      $lastDispatchMap
     * @param array<string, DateTimeImmutable>|null $firstSeenMap null means no cursor store at all
     */
    private function makeDetector(
        AlertDispatcherInterface $dispatcher,
        array $lastDispatchMap,
        ?array $firstSeenMap = null,
    ): DeadManDetector {
        $runStore = $this->makeRunStore($lastDispatchMap);

        return new DeadManDetector(
            $runStore,
            $dispatcher,
            $this->clock,
            self::ENV,
            self::TOLERANCE,
            new NullLogger(),
            $firstSeenMap === null ? null : $this->makeCursorStore($firstSeenMap),
        );
    }

    /** @param array<string, DateTimeImmutable> $firstSeenMap */
    private function makeCursorStore(array $firstSeenMap): ScheduleCursorStoreInterface
    {
        return new class($firstSeenMap, $this->now) implements ScheduleCursorStoreInterface {
            /** @param array<string, DateTimeImmutable> $firstSeenMap */
            public function __construct(
                private array $firstSeenMap,
                private DateTimeImmutable $now,
            ) {}

            public function findCursors(array $scheduleIds, ?string $tenantId): array
            {
                $out = [];
                foreach ($scheduleIds as $id) {
                    $key = $id->toString();
                    if (!isset($this->firstSeenMap[$key])) {
                        continue; // No cursor row: the schedule has never been scanned.
                    }
                    $out[$key] = new CadenceCursor($id, $tenantId, $this->now, 1, $this->firstSeenMap[$key]);
                }

                return $out;
            }

            public function advance(ScheduleId $id, ?string $tenantId, DateTimeImmutable $newCursor, int $expectedVersion): bool
            {
                return true;
            }
        };
    }

    private function makeRunStore(array $lastDispatchMap): ScheduleRunStoreInterface
    {
        return new class($lastDispatchMap) implements ScheduleRunStoreInterface {
            public function __construct(private array $lastDispatchMap) {}

            public function findLastDispatchTimes(array $scheduleIds, ?string $tenantId): array
            {
                $result = [];
                foreach ($scheduleIds as $id) {
                    $key          = is_string($id) ? $id : $id->toString();
                    $result[$key] = $this->lastDispatchMap[$key] ?? null;
                }

                return $result;
            }

            public function insertRun(\Vortos\Scheduler\Fire\ScheduleRun $run): void {}
            public function findLastSlots(array $scheduleIds, ?string $tenantId): array { return []; }
            public function findRunState(\Vortos\Scheduler\Schedule\ScheduleId $scheduleId, string $slot, ?string $tenantId): ?\Vortos\Scheduler\Fire\RunState { return null; }
            public function findRunBySlot(\Vortos\Scheduler\Schedule\ScheduleId $scheduleId, string $slot, ?string $tenantId): ?\Vortos\Scheduler\Fire\ScheduleRun { return null; }
            public function transitionRunState(string $runId, \Vortos\Scheduler\Fire\RunState $newState, \DateTimeImmutable $at): void {}
            public function pruneOldRuns(\DateTimeImmutable $olderThan, ?string $tenantId = null, array $excludeTenantIds = []): PruneResult { return new PruneResult(0, false); }
        };
    }

    private function makeSchedule(
        ?ScheduleId $id = null,
        string $name = 'test-schedule',
        ?Trigger $trigger = null,
        ScheduleStatus $status = ScheduleStatus::Active,
        ?string $tenantId = 'tenant-1',
        array $metadata = [],
    ): Schedule {
        return new Schedule(
            id:       $id ?? ScheduleId::generate(),
            name:     $name,
            source:   ScheduleSource::Dynamic,
            trigger:  $trigger ?? $this->makePastDueTrigger(),
            command:  new CommandSpec(StubAllowlistedCommand::class, []),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   $status,
            tenantId: $tenantId,
            metadata: $metadata,
        );
    }
}

// ── Test doubles ──────────────────────────────────────────────────────────────

final class SpyAlertDispatcher implements AlertDispatcherInterface
{
    /** @var list<AlertEvent> */
    private array $dispatched = [];

    public ?array $lastRoutingOverride = null;

    public function dispatch(AlertEvent $event, ?array $routingOverride = null): DispatchResult
    {
        $this->dispatched[]        = $event;
        $this->lastRoutingOverride = $routingOverride;

        return new DispatchResult(DedupeDecision::New, []);
    }

    /** @return list<AlertEvent> */
    public function dispatched(): array
    {
        return $this->dispatched;
    }
}
