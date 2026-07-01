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
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;

/**
 * Detects "schedule that should have fired but didn't" — the failure mode naive
 * schedulers miss entirely (daemon loop is healthy, but one specific schedule has
 * silently stopped firing).
 *
 * Algorithm (per tick, after fire dispatch):
 *   For each Active schedule:
 *     1. Compute the most recent past due slot: trigger.nextRunAfter(now - tolerance)
 *     2. If that slot is in the future, the schedule has never been due in the window — skip.
 *     3. Query last dispatch time via ScheduleRunStoreInterface::findLastDispatchTimes().
 *     4. If no dispatch exists within (now - tolerance), raise a Critical alert.
 *
 * The check uses a single bulk SQL query so N active schedules costs one round-trip.
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

            return;
        }

        foreach ($candidates as $schedule) {
            try {
                $this->checkSchedule($schedule, $now, $lastDispatches);
            } catch (\Throwable $e) {
                $this->logger->error('DeadManDetector: per-schedule check failed', [
                    'schedule_id' => $schedule->id->toString(),
                    'error'       => $e->getMessage(),
                ]);
            }
        }
    }

    private function checkSchedule(
        Schedule $schedule,
        DateTimeImmutable $now,
        array $lastDispatches,
    ): void {
        $toleranceSec = $this->resolveToleranceSec($schedule, $now);
        $windowStart  = $now->modify("-{$toleranceSec} seconds");

        // Most recent past slot within the window
        $expectedSlot = $schedule->trigger->nextRunAfter($windowStart);

        if ($expectedSlot === null || $expectedSlot > $now) {
            // Schedule has never been due within the tolerance window
            return;
        }

        $lastDispatch = $lastDispatches[$schedule->id->toString()] ?? null;

        if ($lastDispatch !== null && $lastDispatch >= $windowStart) {
            // Dispatched within the tolerance window — healthy
            return;
        }

        $this->raiseAlert($schedule, $expectedSlot, $lastDispatch, $toleranceSec, $now);
    }

    private function resolveToleranceSec(Schedule $schedule, DateTimeImmutable $now): int
    {
        $toleranceSec = isset($schedule->metadata['deadman_tolerance_sec'])
            ? (int) $schedule->metadata['deadman_tolerance_sec']
            : $this->defaultToleranceSec;

        // Auto-bump: for schedules whose period is longer than the tolerance, use 2× period
        // so we don't false-alert on infrequent jobs (e.g. monthly reports).
        $nextAfterNow = $schedule->trigger->nextRunAfter($now);
        $prevSlot     = $schedule->trigger->nextRunAfter($now->modify('-1 year'));

        if ($nextAfterNow !== null && $prevSlot !== null && $nextAfterNow > $now) {
            $periodSec = $nextAfterNow->getTimestamp() - $prevSlot->getTimestamp();
            if ($periodSec > 0) {
                $toleranceSec = max($toleranceSec, $periodSec * 2);
            }
        }

        return $toleranceSec;
    }

    private function raiseAlert(
        Schedule $schedule,
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
                    ruleId:      'scheduler.dead_man.' . $schedule->id->toString(),
                    severity:    Severity::Critical,
                    title:       sprintf('Scheduler dead-man: "%s" has not fired', $schedule->name),
                    summary:     sprintf(
                        'Schedule "%s" (id: %s) was expected to fire at %s but no dispatch was recorded in the last %ds.',
                        $schedule->name,
                        $schedule->id->toString(),
                        $expectedSlot->format(DateTimeInterface::ATOM),
                        $toleranceSec,
                    ),
                    source:      AlertSource::Scheduler,
                    env:         $this->env,
                    tenantId:    $schedule->tenantId,
                    labels:      [
                        'schedule_id'   => $schedule->id->toString(),
                        'schedule_name' => $schedule->name,
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
}
