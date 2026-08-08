<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Observability;

use DateTimeImmutable;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Vortos\Alerts\AlertDispatcherInterface;
use Vortos\Alerts\Event\AlertEvent;
use Vortos\Alerts\Event\AlertSource;
use Vortos\Alerts\Severity;
use Vortos\Scheduler\Clock\ClockPort;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Store\ScheduleCursorStoreInterface;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;

/**
 * Detects "schedule that should have fired but didn't" — the failure mode naive
 * schedulers miss entirely (daemon loop is healthy, but one specific schedule has
 * silently stopped firing).
 *
 * Algorithm (per tick, after fire dispatch):
 *   For each Active schedule:
 *     1. Size the tolerance window from the schedule's own cadence (see longestPeriodSec()).
 *     2. Compute the most recent past due slot: trigger.nextRunAfter(now - tolerance).
 *     3. If that slot is in the future, the schedule has never been due in the window — skip.
 *     4. Query last dispatch time via ScheduleRunStoreInterface::findLastDispatchTimes().
 *     5. Reach a {@see DeadManVerdict}: a dispatch inside the window is healthy; a dispatch older
 *        than it is Overdue; no dispatch at all is judged against the cursor's first-seen instant,
 *        and is Indeterminate when that baseline is missing.
 *
 * Both stores are read with one bulk query each, so N active schedules costs two round-trips.
 *
 * WHAT THIS GOT WRONG, TWICE
 * --------------------------
 * The check was binary — dispatch in window, or Critical — and the window was mis-measured. The
 * period was derived from `nextRunAfter(now - 1 year)`, which is not the previous slot, so every
 * schedule got a ~2-year tolerance: long enough that a genuinely dead daily job would not have
 * paged until 2028, and long enough that the "not yet due" guard never engaged, so every newly
 * registered schedule alerted as dead before its first run. The two defects hid each other. An
 * inert alarm produced no alerts, which looked like health, and the only alerts it did produce were
 * false ones about schedules that were fine.
 *
 * Safety: check() NEVER throws — any per-schedule or alert-dispatch failure is caught,
 * logged, and swallowed. A broken alert backend must not stall the daemon tick.
 *
 * Per-schedule overrides:
 *   $schedule->metadata['deadman_tolerance_sec']  — overrides global $defaultToleranceSec
 *   $schedule->metadata['deadman_enabled'] = false — opts the schedule out entirely
 *   $schedule->metadata['deadman_alert_routing']   — comma-separated channel keys passed
 *                                                    to AlertDispatcherInterface as routingOverride
 */
final class DeadManDetector
{
    public function __construct(
        private readonly ScheduleRunStoreInterface $runStore,
        private readonly AlertDispatcherInterface $dispatcher,
        private readonly ClockPort $clock,
        private readonly string $env,
        private readonly int $defaultToleranceSec = 300,
        private readonly LoggerInterface $logger = new NullLogger(),
        // Optional so containers without a cursor store keep constructing. Without it every
        // never-dispatched schedule is Indeterminate rather than silently assumed dead, which is
        // the honest reading of "no baseline available".
        private readonly ?ScheduleCursorStoreInterface $cursors = null,
    ) {}

    /**
     * Check all active schedules for overdue fires. Never throws.
     *
     * @param list<Schedule> $activeSchedules
     */
    public function check(array $activeSchedules): void
    {
        if ($activeSchedules === []) {
            return;
        }

        $now = $this->clock->now();

        // Collect schedules that need checking (exclude paused/disabled and opted-out)
        $candidates = array_filter(
            $activeSchedules,
            fn (Schedule $s) => $s->status === ScheduleStatus::Active
                && ($s->metadata['deadman_enabled'] ?? 'true') !== 'false',
        );

        if ($candidates === []) {
            return;
        }

        // Bulk query: one round-trip for all schedule IDs
        $scheduleIds   = array_map(fn (Schedule $s) => $s->id, array_values($candidates));
        $lastDispatches = [];

        try {
            $lastDispatches = $this->runStore->findLastDispatchTimes($scheduleIds, null);
        } catch (\Throwable $e) {
            $this->logger->error('DeadManDetector: failed to query last dispatch times', [
                'error' => $e->getMessage(),
            ]);

            // Previously a silent return. An unreadable run ledger means every schedule on this
            // node is now unmonitored, and the only outward sign was the absence of alerts — which
            // is indistinguishable from everything being fine. Say so once, for the node, rather
            // than once per schedule.
            $this->raiseUnreadableLedger(count($candidates), $e, $now);

            return;
        }

        $firstSeen = $this->firstSeenAt($scheduleIds);

        foreach ($candidates as $schedule) {
            try {
                $this->checkSchedule($schedule, $now, $lastDispatches, $firstSeen);
            } catch (\Throwable $e) {
                $this->logger->error('DeadManDetector: per-schedule check failed', [
                    'schedule_id' => $schedule->id->toString(),
                    'error'       => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param array<string, DateTimeImmutable|null> $lastDispatches keyed by schedule id
     * @param array<string, DateTimeImmutable|null> $firstSeen      keyed by schedule id
     */
    private function checkSchedule(
        Schedule $schedule,
        DateTimeImmutable $now,
        array $lastDispatches,
        array $firstSeen,
    ): void {
        $toleranceSec = $this->resolveToleranceSec($schedule, $now);
        $windowStart  = $now->modify("-{$toleranceSec} seconds");

        // Most recent past slot within the window
        $expectedSlot = $schedule->trigger->nextRunAfter($windowStart);

        if ($expectedSlot === null || $expectedSlot > $now) {
            // Schedule has never been due within the tolerance window
            return;
        }

        $id = $schedule->id->toString();
        $lastDispatch = $lastDispatches[$id] ?? null;

        $verdict = $this->verdict($id, $lastDispatch, $windowStart, $firstSeen);

        if (!$verdict->isAlertable()) {
            return;
        }

        $this->raiseAlert($schedule, $verdict, $expectedSlot, $lastDispatch, $toleranceSec, $now);
    }

    /**
     * Three states, not two. See {@see DeadManVerdict}.
     *
     * @param array<string, DateTimeImmutable|null> $firstSeen
     */
    private function verdict(
        string $id,
        ?DateTimeImmutable $lastDispatch,
        DateTimeImmutable $windowStart,
        array $firstSeen,
    ): DeadManVerdict {
        if ($lastDispatch !== null) {
            return $lastDispatch >= $windowStart ? DeadManVerdict::Fired : DeadManVerdict::Overdue;
        }

        // No dispatch has ever been recorded. On its own that says nothing: a schedule registered a
        // minute ago and one that died a month ago are identical in the run ledger. The cursor's
        // first-seen instant is the only thing that separates them, and run-history pruning makes
        // it the durable one — pruned runs can turn a long-dead schedule back into "never".
        $seen = $firstSeen[$id] ?? null;

        if ($seen === null) {
            return DeadManVerdict::Indeterminate;
        }

        // Not around long enough to have been expected to fire yet.
        return $seen > $windowStart ? DeadManVerdict::Fired : DeadManVerdict::NeverFired;
    }

    /**
     * When each schedule was first observed, or null where that is unknown.
     *
     * A missing cursor store, a failed query and a NULL column all mean the same thing here, and it
     * is not "brand new" — it is "cannot tell", which the caller turns into an Indeterminate
     * verdict rather than a guess in either direction.
     *
     * @param  list<ScheduleId> $scheduleIds
     * @return array<string, DateTimeImmutable|null>
     */
    private function firstSeenAt(array $scheduleIds): array
    {
        if ($this->cursors === null) {
            return [];
        }

        try {
            $cursors = $this->cursors->findCursors($scheduleIds, null);
        } catch (\Throwable $e) {
            $this->logger->error('DeadManDetector: failed to read schedule cursors', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $out = [];
        foreach ($cursors as $id => $cursor) {
            $out[$id] = $cursor->firstSeenAt;
        }

        return $out;
    }

    private function resolveToleranceSec(Schedule $schedule, DateTimeImmutable $now): int
    {
        $toleranceSec = isset($schedule->metadata['deadman_tolerance_sec'])
            ? (int) $schedule->metadata['deadman_tolerance_sec']
            : $this->defaultToleranceSec;

        // Auto-bump: a schedule that fires less often than the tolerance window would otherwise
        // look overdue between every pair of legitimate runs, so infrequent jobs get 2× their own
        // period instead of the global default.
        $periodSec = $this->longestPeriodSec($schedule);

        if ($periodSec !== null && $periodSec > 0) {
            $toleranceSec = max($toleranceSec, $periodSec * 2);
        }

        return $toleranceSec;
    }

    /**
     * The schedule's firing period, measured from its own upcoming slots.
     *
     * WHY FORWARD, AND WHY THE LONGEST GAP
     * ------------------------------------
     * This used to be `nextRunAfter(now) - nextRunAfter(now - 1 year)`. The second term reads as
     * "the previous slot" and is not: `nextRunAfter` returns the first slot AFTER its argument, so
     * it produced the first slot after this time last year — roughly a year ago. Every schedule
     * firing more often than annually therefore measured its period as ~1 year and was handed a
     * ~2-year tolerance. In production that meant a daily sweep would have had to stay silent for
     * 730 days before this detector said anything: the dead-man was inert, which is the failure it
     * is supposed to catch, in the component built to catch it.
     *
     * Walking forward from `now` avoids the trap entirely — consecutive future slots are what a
     * period actually is. The LONGEST gap across several slots is used rather than the first,
     * because plenty of real triggers are unevenly spaced: a weekday-only cron has a one-day gap
     * four times a week and a three-day gap over the weekend, and sizing the window off Tuesday
     * guarantees a false alert every Monday.
     */
    private function longestPeriodSec(Schedule $schedule): ?int
    {
        $samples = 5;
        $slot = $this->clock->now();
        $longest = null;

        for ($i = 0; $i < $samples; $i++) {
            $next = $schedule->trigger->nextRunAfter($slot);
            if ($next === null) {
                break; // One-shot, or a trigger that will not fire again: no period to measure.
            }

            if ($i > 0) {
                $gap = $next->getTimestamp() - $slot->getTimestamp();
                $longest = $longest === null ? $gap : max($longest, $gap);
            }

            $slot = $next;
        }

        return $longest;
    }

    private function raiseAlert(
        Schedule $schedule,
        DeadManVerdict $verdict,
        DateTimeImmutable $expectedSlot,
        ?DateTimeImmutable $lastDispatch,
        int $toleranceSec,
        DateTimeImmutable $now,
    ): void {
        $routingOverride = null;

        if (isset($schedule->metadata['deadman_alert_routing'])) {
            $channels = array_filter(array_map(
                'trim',
                explode(',', (string) $schedule->metadata['deadman_alert_routing']),
            ));

            if ($channels !== []) {
                $routingOverride = array_values($channels);
            }
        }

        try {
            $this->dispatcher->dispatch(
                AlertEvent::scrubbed(
                    // The verdict is part of the rule id: "stopped firing" and "cannot tell" are
                    // different findings with different responses, and folding them onto one id
                    // would let the dedupe window suppress one behind the other.
                    ruleId:      sprintf('scheduler.dead_man.%s.%s', $verdict->value, $schedule->id->toString()),
                    severity:    $this->severityFor($verdict),
                    title:       $this->titleFor($verdict, $schedule),
                    summary:     $this->summaryFor($verdict, $schedule, $expectedSlot, $toleranceSec),
                    source:      AlertSource::Scheduler,
                    env:         $this->env,
                    tenantId:    $schedule->tenantId,
                    labels:      [
                        'schedule_id'   => $schedule->id->toString(),
                        'schedule_name' => $schedule->name,
                        'verdict'       => $verdict->value,
                    ],
                    annotations: [
                        'last_dispatch'  => $lastDispatch?->format(DateTimeInterface::ATOM) ?? 'never',
                        'expected_slot'  => $expectedSlot->format(DateTimeInterface::ATOM),
                        'tolerance_sec'  => (string) $toleranceSec,
                    ],
                    links:       [],
                    occurredAt:  $now,
                ),
                $routingOverride,
            );
        } catch (\Throwable $e) {
            $this->logger->error('DeadManDetector: failed to dispatch alert', [
                'schedule_id' => $schedule->id->toString(),
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Indeterminate is a Warning, not a Critical.
     *
     * "This schedule has stopped" and "I could not check this schedule" both need to reach someone,
     * but they are not the same call to make at three in the morning, and paging at the same
     * severity for a monitoring gap is the reliable way to get the real alerts muted too.
     */
    private function severityFor(DeadManVerdict $verdict): Severity
    {
        return $verdict === DeadManVerdict::Indeterminate ? Severity::Warning : Severity::Critical;
    }

    private function titleFor(DeadManVerdict $verdict, Schedule $schedule): string
    {
        return match ($verdict) {
            DeadManVerdict::Overdue => sprintf('Scheduler dead-man: "%s" has stopped firing', $schedule->name),
            DeadManVerdict::NeverFired => sprintf('Scheduler dead-man: "%s" has never fired', $schedule->name),
            DeadManVerdict::Indeterminate => sprintf('Scheduler dead-man: cannot verify "%s"', $schedule->name),
            DeadManVerdict::Fired => sprintf('Scheduler dead-man: "%s"', $schedule->name),
        };
    }

    private function summaryFor(
        DeadManVerdict $verdict,
        Schedule $schedule,
        DateTimeImmutable $expectedSlot,
        int $toleranceSec,
    ): string {
        $id = $schedule->id->toString();
        $slot = $expectedSlot->format(DateTimeInterface::ATOM);

        return match ($verdict) {
            DeadManVerdict::Overdue => sprintf(
                'Schedule "%s" (id: %s) has dispatched before, but not since %s — nothing in the last %ds.',
                $schedule->name,
                $id,
                $slot,
                $toleranceSec,
            ),
            DeadManVerdict::NeverFired => sprintf(
                'Schedule "%s" (id: %s) has no recorded dispatch at all, and has been registered for '
                . 'longer than its %ds tolerance. It was due at %s. Check that its command is on the '
                . 'scheduler allowlist and that the daemon is leader on some node.',
                $schedule->name,
                $id,
                $toleranceSec,
                $slot,
            ),
            DeadManVerdict::Indeterminate => sprintf(
                'Schedule "%s" (id: %s) has no recorded dispatch, and there is no record of when it '
                . 'was first seen, so a new schedule cannot be told apart from a dead one. This is a '
                . 'gap in the check itself, not a confirmed outage — the schedule may be fine.',
                $schedule->name,
                $id,
            ),
            DeadManVerdict::Fired => sprintf('Schedule "%s" (id: %s) is healthy.', $schedule->name, $id),
        };
    }

    /** One alert for the node when the run ledger cannot be read at all. */
    private function raiseUnreadableLedger(int $scheduleCount, \Throwable $cause, DateTimeImmutable $now): void
    {
        try {
            $this->dispatcher->dispatch(AlertEvent::scrubbed(
                ruleId:      'scheduler.dead_man.ledger_unreadable',
                severity:    Severity::Warning,
                title:       'Scheduler dead-man cannot read dispatch history',
                summary:     sprintf(
                    'The dead-man check could not query the run ledger, so %d active schedule(s) are '
                    . 'currently unmonitored for overdue fires. They may all be firing normally; the '
                    . 'check simply cannot say either way.',
                    $scheduleCount,
                ),
                source:      AlertSource::Scheduler,
                env:         $this->env,
                tenantId:    null,
                labels:      ['verdict' => DeadManVerdict::Indeterminate->value],
                annotations: ['error' => $cause->getMessage()],
                links:       [],
                occurredAt:  $now,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('DeadManDetector: failed to dispatch unreadable-ledger alert', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
