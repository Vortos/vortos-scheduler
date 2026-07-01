<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Doctor;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Vortos\Scheduler\Clock\ClockPort;
use Vortos\Scheduler\Fire\RunState;
use Vortos\Scheduler\Lease\LeasePort;
use Vortos\Scheduler\Lease\LeaseToken;
use Vortos\Scheduler\Registry\PruneSchedulerRunsSchedule;
use Vortos\Scheduler\Registry\ScheduleResolver;
use Vortos\Scheduler\Schedule\Policy\FireEachMissed;
use Vortos\Scheduler\Schedule\Policy\SkipMissed;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\Trigger\RecurringTrigger;
use Vortos\Scheduler\Security\Approval\ApprovalAction;
use Vortos\Scheduler\Security\Approval\ApprovalStatus;
use Vortos\Scheduler\Security\Approval\FourEyesApprovalStoreInterface;
use Vortos\Scheduler\Security\CommandSpecValidator;
use Vortos\Scheduler\Store\RunRetentionOverride;
use Vortos\Scheduler\Store\RunRetentionOverrideStoreInterface;
use Vortos\Scheduler\Store\ScheduleStoreInterface;

/**
 * Fail-closed preflight health inspector for the scheduler subsystem.
 *
 * Runs 11 checks (C1–C11) that cover cron validity, name collisions,
 * command allowlisting, lease driver reachability, migration state,
 * 4-eyes approval coverage, misfire policy safety, catchup bounds,
 * shard lease probe, auto-prune config + liveness (C10), and fire-queue
 * consumer liveness (C11, S12).
 */
final class SchedulerDoctor implements SchedulerDoctorPort
{
    private const PRUNE_LIVENESS_STALE_HOURS = 48;

    public function __construct(
        private readonly ScheduleResolver                  $resolver,
        private readonly ScheduleStoreInterface            $dynamicStore,
        private readonly LeasePort                         $leasePort,
        private readonly Connection                        $connection,
        private readonly ClockPort                         $clock,
        private readonly ?CommandSpecValidator             $validator,
        private readonly ?FourEyesApprovalStoreInterface  $approvalStore,
        private readonly string                            $tablePrefix = 'vortos_',
        private readonly int                               $shardCount = 1,
        private readonly int                               $maxCatchupAgeSec = 86400,
        // Default 0 (disabled), not the app-level default of 30: this constructor
        // default only matters for callers that don't pass it explicitly (mainly
        // tests exercising other checks) — 0 skips C10's table queries entirely,
        // so it never assumes a `scheduler_runs` schema it wasn't given.
        private readonly int                               $runRetentionDays = 0,
        private readonly ?RunRetentionOverrideStoreInterface $retentionOverrideStore = null,
        private readonly bool                              $fireQueueConsumerInstalled = false,
        private readonly int                               $consumeStallThresholdSec = 120,
    ) {}

    public function run(): SchedulerDoctorReport
    {
        $now      = $this->clock->now();
        $statics  = $this->resolver->getRegistry()->all();
        $dynamics = iterator_to_array($this->dynamicStore->findAll(null));

        /** @var list<Schedule> $allSchedules */
        $allSchedules = array_merge($statics, $dynamics);

        $findings = [
            $this->checkCronExpressionsValid($allSchedules, $now),
            $this->checkNoNameCollision($statics, $dynamics),
            $this->checkCommandAllowlistValid($allSchedules),
            $this->checkLeaseDriverReachable(),
            $this->checkMigrationsApplied(),
            $this->checkSensitiveApprovalsPresent($allSchedules),
            $this->checkSensitiveHaveExplicitMisfirePolicy($allSchedules),
            $this->checkCatchupBoundsValid($allSchedules),
            $this->checkShardConfigValid(),
            $this->checkRetentionStatusValid($now),
            $this->checkFireQueueConsumerHealthy($now),
        ];

        return new SchedulerDoctorReport($findings);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // C1 — Cron expressions are parseable and yield valid next-run times
    // ─────────────────────────────────────────────────────────────────────────

    /** @param list<Schedule> $schedules */
    private function checkCronExpressionsValid(array $schedules, DateTimeImmutable $now): SchedulerDoctorFinding
    {
        $failures = [];

        foreach ($schedules as $schedule) {
            try {
                $next = $schedule->trigger->nextRunAfter($now);

                if ($schedule->trigger instanceof RecurringTrigger && $next === null) {
                    $failures[] = sprintf(
                        '"%s" (%s): RecurringTrigger returned null next-run-after — expression may be unsatisfiable.',
                        $schedule->name,
                        $schedule->id->toString(),
                    );
                }
            } catch (\Throwable $e) {
                $failures[] = sprintf(
                    '"%s" (%s): trigger->nextRunAfter() threw: %s',
                    $schedule->name,
                    $schedule->id->toString(),
                    $e->getMessage(),
                );
            }
        }

        if ($failures !== []) {
            return new SchedulerDoctorFinding(
                'C1',
                SchedulerDoctorStatus::Fail,
                sprintf('%d schedule(s) have invalid trigger expressions.', count($failures)),
                implode("\n", $failures),
                'Fix the cron expression or trigger definition in the schedule.',
            );
        }

        return new SchedulerDoctorFinding('C1', SchedulerDoctorStatus::Pass, 'All trigger expressions are valid.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // C2 — No name or ID collision between static and dynamic schedules
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param list<Schedule> $statics
     * @param list<Schedule> $dynamics
     */
    private function checkNoNameCollision(array $statics, array $dynamics): SchedulerDoctorFinding
    {
        $staticNames = [];
        $staticIds   = [];

        foreach ($statics as $s) {
            $staticNames[$s->name]      = $s->id->toString();
            $staticIds[$s->id->toString()] = $s->name;
        }

        $collisions = [];

        foreach ($dynamics as $d) {
            if ($d->tenantId === null && isset($staticNames[$d->name])) {
                $collisions[] = sprintf(
                    'Name collision: dynamic "%s" (id: %s, tenantId: null) matches static schedule id: %s.',
                    $d->name, $d->id->toString(), $staticNames[$d->name],
                );
            }

            if (isset($staticIds[$d->id->toString()])) {
                $collisions[] = sprintf(
                    'ID collision: dynamic id "%s" (name: "%s") matches static schedule "%s".',
                    $d->id->toString(), $d->name, $staticIds[$d->id->toString()],
                );
            }
        }

        if ($collisions !== []) {
            return new SchedulerDoctorFinding(
                'C2',
                SchedulerDoctorStatus::Fail,
                sprintf('%d schedule name/ID collision(s) detected.', count($collisions)),
                implode("\n", $collisions),
                'Rename or remove conflicting dynamic schedules before deploying.',
            );
        }

        return new SchedulerDoctorFinding('C2', SchedulerDoctorStatus::Pass, 'No name or ID collisions detected.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // C3 — All scheduled command classes are allowlisted
    // ─────────────────────────────────────────────────────────────────────────

    /** @param list<Schedule> $schedules */
    private function checkCommandAllowlistValid(array $schedules): SchedulerDoctorFinding
    {
        if ($this->validator === null) {
            return new SchedulerDoctorFinding(
                'C3',
                SchedulerDoctorStatus::Skip,
                'No CommandSpecValidator registered — allowlist check skipped.',
            );
        }

        $violations = [];

        foreach ($schedules as $schedule) {
            if (!$this->validator->isAllowlisted($schedule->command->commandClass)) {
                $violations[] = sprintf(
                    '"%s" (%s): command class "%s" is not allowlisted.',
                    $schedule->name,
                    $schedule->id->toString(),
                    $schedule->command->commandClass,
                );
            }
        }

        if ($violations !== []) {
            return new SchedulerDoctorFinding(
                'C3',
                SchedulerDoctorStatus::Fail,
                sprintf('%d schedule(s) reference non-allowlisted command classes.', count($violations)),
                implode("\n", $violations),
                'Add #[SchedulableCommand] to each command class or update the allowlist.',
            );
        }

        return new SchedulerDoctorFinding('C3', SchedulerDoctorStatus::Pass, 'All command classes are allowlisted.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // C4 — Lease driver is reachable and functional
    // ─────────────────────────────────────────────────────────────────────────

    private function checkLeaseDriverReachable(): SchedulerDoctorFinding
    {
        $key   = 'scheduler:doctor:probe:' . bin2hex(random_bytes(4));
        $token = LeaseToken::generate();
        $lease = null;

        try {
            $lease = $this->leasePort->acquire($key, $token, 5);

            if ($lease === null) {
                return new SchedulerDoctorFinding(
                    'C4',
                    SchedulerDoctorStatus::Fail,
                    'Lease driver returned null for probe acquire — driver may be unavailable.',
                    'acquire() returned null for probe key: ' . $key,
                    'Check the lease driver configuration (Redis/DBAL) and connectivity.',
                );
            }

            return new SchedulerDoctorFinding('C4', SchedulerDoctorStatus::Pass, 'Lease driver is reachable.');
        } catch (\Throwable $e) {
            return new SchedulerDoctorFinding(
                'C4',
                SchedulerDoctorStatus::Fail,
                'Lease driver threw an exception during probe acquire.',
                $e->getMessage(),
                'Check the lease driver configuration and network connectivity.',
            );
        } finally {
            if ($lease !== null) {
                try {
                    $this->leasePort->release($lease);
                } catch (\Throwable) {
                    // Best-effort release; do not shadow the main finding.
                }
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // C5 — Required DB tables are present (migrations applied)
    // ─────────────────────────────────────────────────────────────────────────

    private function checkMigrationsApplied(): SchedulerDoctorFinding
    {
        $required = [
            $this->tablePrefix . 'scheduler_schedules',
            $this->tablePrefix . 'scheduler_runs',
            $this->tablePrefix . 'scheduler_audit_log',
            $this->tablePrefix . 'scheduler_audit_checkpoints',
            $this->tablePrefix . 'scheduler_static_overrides',
            $this->tablePrefix . 'scheduler_fire_queue',
            $this->tablePrefix . 'scheduler_run_retention_overrides',
        ];

        $missing = [];

        foreach ($required as $table) {
            try {
                $this->connection->executeQuery("SELECT 1 FROM {$table} LIMIT 1");
            } catch (\Throwable) {
                $missing[] = $table;
            }
        }

        if ($missing !== []) {
            return new SchedulerDoctorFinding(
                'C5',
                SchedulerDoctorStatus::Fail,
                sprintf('%d required table(s) are missing.', count($missing)),
                'Missing tables: ' . implode(', ', $missing),
                'Run pending migrations: php bin/console vortos:migrate or your migration runner.',
            );
        }

        return new SchedulerDoctorFinding('C5', SchedulerDoctorStatus::Pass, 'All required tables are present.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // C6 — Sensitive schedules have a recorded 4-eyes approval
    // ─────────────────────────────────────────────────────────────────────────

    /** @param list<Schedule> $schedules */
    private function checkSensitiveApprovalsPresent(array $schedules): SchedulerDoctorFinding
    {
        if ($this->approvalStore === null) {
            return new SchedulerDoctorFinding(
                'C6',
                SchedulerDoctorStatus::Skip,
                'No FourEyesApprovalStore registered — approval check skipped.',
            );
        }

        $missing = [];

        foreach ($schedules as $schedule) {
            if (!$schedule->sensitive) {
                continue;
            }

            $requests = $this->approvalStore->findBySchedule($schedule->id);
            $hasApprovedActivation = false;

            foreach ($requests as $req) {
                if ($req->action === ApprovalAction::Activate
                    && $req->status === ApprovalStatus::Approved) {
                    $hasApprovedActivation = true;
                    break;
                }
            }

            if (!$hasApprovedActivation) {
                $missing[] = sprintf(
                    '"%s" (%s): sensitive schedule has no approved Activate request.',
                    $schedule->name,
                    $schedule->id->toString(),
                );
            }
        }

        if ($missing !== []) {
            return new SchedulerDoctorFinding(
                'C6',
                SchedulerDoctorStatus::Fail,
                sprintf('%d sensitive schedule(s) lack a recorded activation approval.', count($missing)),
                implode("\n", $missing),
                'Request and obtain 4-eyes approval for each sensitive schedule activation.',
            );
        }

        return new SchedulerDoctorFinding('C6', SchedulerDoctorStatus::Pass, 'All sensitive schedules have approval records.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // C7 — Sensitive schedules have explicit misfire policy (not implicit SkipMissed)
    // ─────────────────────────────────────────────────────────────────────────

    /** @param list<Schedule> $schedules */
    private function checkSensitiveHaveExplicitMisfirePolicy(array $schedules): SchedulerDoctorFinding
    {
        $violations = [];

        foreach ($schedules as $schedule) {
            if (!$schedule->sensitive) {
                continue;
            }

            if ($schedule->misfire instanceof SkipMissed
                && ($schedule->metadata['misfire_policy_explicit'] ?? '') !== 'true') {
                $violations[] = sprintf(
                    '"%s" (%s): sensitive schedule uses implicit SkipMissed without misfire_policy_explicit=true.',
                    $schedule->name,
                    $schedule->id->toString(),
                );
            }
        }

        if ($violations !== []) {
            return new SchedulerDoctorFinding(
                'C7',
                SchedulerDoctorStatus::Fail,
                sprintf('%d sensitive schedule(s) lack explicit misfire policy declaration.', count($violations)),
                implode("\n", $violations),
                'Set misfire_policy_explicit=true in schedule metadata or choose a non-default misfire policy.',
            );
        }

        return new SchedulerDoctorFinding('C7', SchedulerDoctorStatus::Pass, 'All sensitive schedules have explicit misfire policy.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // C8 — Catchup bounds and FireEachMissed caps are within valid range
    // ─────────────────────────────────────────────────────────────────────────

    /** @param list<Schedule> $schedules */
    private function checkCatchupBoundsValid(array $schedules): SchedulerDoctorFinding
    {
        if ($this->maxCatchupAgeSec <= 0) {
            return new SchedulerDoctorFinding(
                'C8',
                SchedulerDoctorStatus::Fail,
                'maxCatchupAgeSec must be > 0.',
                sprintf('Configured maxCatchupAgeSec = %d', $this->maxCatchupAgeSec),
                'Set SCHEDULER_MAX_CATCHUP_AGE_SECONDS to a positive integer.',
            );
        }

        $violations = [];

        foreach ($schedules as $schedule) {
            if ($schedule->misfire instanceof FireEachMissed) {
                $cap = $schedule->misfire->cap;
                if ($cap < FireEachMissed::MIN_CAP || $cap > FireEachMissed::MAX_CAP) {
                    $violations[] = sprintf(
                        '"%s" (%s): FireEachMissed cap %d is outside [%d, %d].',
                        $schedule->name,
                        $schedule->id->toString(),
                        $cap,
                        FireEachMissed::MIN_CAP,
                        FireEachMissed::MAX_CAP,
                    );
                }
            }
        }

        if ($violations !== []) {
            return new SchedulerDoctorFinding(
                'C8',
                SchedulerDoctorStatus::Fail,
                sprintf('%d schedule(s) have invalid FireEachMissed caps.', count($violations)),
                implode("\n", $violations),
                'FireEachMissed cap must be between 1 and 1000 inclusive.',
            );
        }

        return new SchedulerDoctorFinding('C8', SchedulerDoctorStatus::Pass, 'Catchup bounds are valid.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // C9 — Shard count is valid and probe leases succeed for each shard
    // ─────────────────────────────────────────────────────────────────────────

    private function checkShardConfigValid(): SchedulerDoctorFinding
    {
        if ($this->shardCount < 1) {
            return new SchedulerDoctorFinding(
                'C9',
                SchedulerDoctorStatus::Fail,
                sprintf('shardCount must be >= 1, got %d.', $this->shardCount),
                '',
                'Set SCHEDULER_SHARD_COUNT to a positive integer.',
            );
        }

        $failures = [];

        for ($s = 0; $s < $this->shardCount; $s++) {
            $key   = 'scheduler:doctor:shard-probe:' . $s . ':' . bin2hex(random_bytes(4));
            $token = LeaseToken::generate();
            $lease = null;

            try {
                $lease = $this->leasePort->acquire($key, $token, 5);

                if ($lease === null) {
                    $failures[] = sprintf('Shard %d: acquire returned null.', $s);
                }
            } catch (\Throwable $e) {
                $failures[] = sprintf('Shard %d: %s', $s, $e->getMessage());
            } finally {
                if ($lease !== null) {
                    try {
                        $this->leasePort->release($lease);
                    } catch (\Throwable) {
                        // Best-effort.
                    }
                }
            }
        }

        if ($failures !== []) {
            return new SchedulerDoctorFinding(
                'C9',
                SchedulerDoctorStatus::Fail,
                sprintf('%d shard probe(s) failed.', count($failures)),
                implode("\n", $failures),
                'Check lease driver connectivity and shard configuration.',
            );
        }

        return new SchedulerDoctorFinding(
            'C9',
            SchedulerDoctorStatus::Pass,
            sprintf('Shard config valid; all %d shard probe(s) succeeded.', $this->shardCount),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // C10 — Auto-prune config + liveness
    //
    // Deliberately proves the feature is actually working, not just configured:
    // "auto-prune active, retention = 30 days" while nothing has ever fired would
    // be false confidence given the fire-queue-consumer gap C11 exists to catch.
    // ─────────────────────────────────────────────────────────────────────────

    private function checkRetentionStatusValid(DateTimeImmutable $now): SchedulerDoctorFinding
    {
        if ($this->runRetentionDays <= 0) {
            return new SchedulerDoctorFinding(
                'C10',
                SchedulerDoctorStatus::Pass,
                'Auto-prune disabled (SCHEDULER_RUN_RETENTION_DAYS=0).',
            );
        }

        $overrides = $this->retentionOverrideStore?->findAll() ?? [];
        $overrideLines = array_map(
            static fn (RunRetentionOverride $o) => sprintf(
                '%s → %s',
                $o->tenantId,
                $o->isExempt() ? '0 days [legal hold]' : $o->retentionDays . ' days',
            ),
            $overrides,
        );

        $scheduleId = PruneSchedulerRunsSchedule::SCHEDULE_ID;
        $runsTable  = $this->tablePrefix . 'scheduler_runs';

        $attemptCount = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$runsTable} WHERE schedule_id = ?",
            [$scheduleId],
        );

        if ($attemptCount === 0) {
            return new SchedulerDoctorFinding(
                'C10',
                SchedulerDoctorStatus::Skip,
                'Auto-prune is configured but has not fired yet — expected before the next scheduled run.',
                sprintf('Global retention = %d days, %d tenant override(s).', $this->runRetentionDays, count($overrides)),
            );
        }

        $lastCompletedRaw = $this->connection->fetchOne(
            "SELECT MAX(completed_at) FROM {$runsTable} WHERE schedule_id = ? AND run_state = ?",
            [$scheduleId, RunState::Completed->value],
        );

        $lastCompletedAt = ($lastCompletedRaw !== false && $lastCompletedRaw !== null)
            ? new DateTimeImmutable((string) $lastCompletedRaw)
            : null;

        if ($lastCompletedAt === null
            || $lastCompletedAt->modify(sprintf('+%d hours', self::PRUNE_LIVENESS_STALE_HOURS)) < $now) {
            return new SchedulerDoctorFinding(
                'C10',
                SchedulerDoctorStatus::Fail,
                $lastCompletedAt === null
                    ? 'Auto-prune has attempted to fire but has never completed successfully.'
                    : sprintf(
                        'Auto-prune has not completed successfully in >%dh (last: %s).',
                        self::PRUNE_LIVENESS_STALE_HOURS,
                        $lastCompletedAt->format(DateTimeInterface::ATOM),
                    ),
                sprintf('Global retention = %d days, %d tenant override(s).', $this->runRetentionDays, count($overrides)),
                'Check that scheduler:consume --loop is running (see C11) and inspect '
                . 'vortos_scheduler_audit_log for runs.pruned entries.',
            );
        }

        return new SchedulerDoctorFinding(
            'C10',
            SchedulerDoctorStatus::Pass,
            sprintf(
                'Auto-prune active, global retention = %d days, %d tenant override(s); last completed %s.',
                $this->runRetentionDays,
                count($overrides),
                $lastCompletedAt->format(DateTimeInterface::ATOM),
            ),
            implode("\n", $overrideLines),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // C11 — Fire-queue consumer liveness (S12)
    //
    // Generic health signal for the gap found while building auto-prune: nothing
    // consumed vortos_scheduler_fire_queue, so no scheduled command ever ran. This
    // check catches that class of failure for ANY schedule, not just prune.
    // ─────────────────────────────────────────────────────────────────────────

    private function checkFireQueueConsumerHealthy(DateTimeImmutable $now): SchedulerDoctorFinding
    {
        if (!$this->fireQueueConsumerInstalled) {
            return new SchedulerDoctorFinding(
                'C11',
                SchedulerDoctorStatus::Skip,
                'CQRS CommandBus not installed — fire-queue consumer check skipped.',
            );
        }

        $queueTable = $this->tablePrefix . 'scheduler_fire_queue';

        $oldestPendingRaw = $this->connection->fetchOne(
            "SELECT MIN(created_at) FROM {$queueTable} WHERE status = 'pending'",
        );

        if ($oldestPendingRaw === false || $oldestPendingRaw === null) {
            return new SchedulerDoctorFinding('C11', SchedulerDoctorStatus::Pass, 'Fire queue is empty — draining normally.');
        }

        $oldestAt = new DateTimeImmutable((string) $oldestPendingRaw);
        $ageSec   = $now->getTimestamp() - $oldestAt->getTimestamp();

        if ($ageSec <= $this->consumeStallThresholdSec) {
            return new SchedulerDoctorFinding(
                'C11',
                SchedulerDoctorStatus::Pass,
                sprintf('Fire queue draining normally (oldest pending row: %ds old).', $ageSec),
            );
        }

        $pendingCount = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$queueTable} WHERE status = 'pending'",
        );

        return new SchedulerDoctorFinding(
            'C11',
            SchedulerDoctorStatus::Fail,
            sprintf('%d row(s) pending in the fire queue; oldest is %ds old.', $pendingCount, $ageSec),
            '',
            'The fire-queue consumer (scheduler:consume --loop) does not appear to be running or is '
            . 'falling behind — scheduled commands are not executing.',
        );
    }
}
