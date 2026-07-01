<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Doctor;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Vortos\Scheduler\Clock\ClockPort;
use Vortos\Scheduler\Lease\LeasePort;
use Vortos\Scheduler\Lease\LeaseToken;
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
use Vortos\Scheduler\Store\ScheduleStoreInterface;

/**
 * Fail-closed preflight health inspector for the scheduler subsystem.
 *
 * Runs 9 checks (C1–C9) that cover cron validity, name collisions,
 * command allowlisting, lease driver reachability, migration state,
 * 4-eyes approval coverage, misfire policy safety, catchup bounds,
 * and shard lease probe.
 */
final class SchedulerDoctor implements SchedulerDoctorPort
{
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
            $this->tablePrefix . 'scheduler_static_overrides',
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
}
