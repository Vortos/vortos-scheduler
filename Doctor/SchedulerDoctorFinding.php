<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Doctor;

final readonly class SchedulerDoctorFinding
{
    /**
     * @param bool $gatesDeploy Whether a failure of this check should BLOCK a deployment.
     *
     *     Distinguishes two kinds of check that were previously conflated. Most checks are about
     *     configuration and capability — "will this release work?" — and a failure must stop the
     *     deploy. A few are about live runtime state — "is what is currently running healthy?" —
     *     and gating on those is backwards, because a deployment is usually the REMEDY.
     *
     *     C14 is the case that proved it: a release fixing a bug that had left 11 schedules
     *     overdue was blocked by the check reporting those 11 overdue schedules. The gate refused
     *     to ship the only thing that would clear it. A runtime-state check must be loud in
     *     `scheduler:doctor` and in alerting, and silent as a deploy gate.
     */
    public function __construct(
        public string               $checkId,
        public SchedulerDoctorStatus $status,
        public string               $summary,
        public string               $detail      = '',
        public string               $remediation = '',
        public bool                 $gatesDeploy = true,
    ) {}

    public function isFailure(): bool
    {
        return $this->status === SchedulerDoctorStatus::Fail;
    }

    /** A failure that must block a deployment, as opposed to one describing current runtime state. */
    public function isDeployBlockingFailure(): bool
    {
        return $this->isFailure() && $this->gatesDeploy;
    }

    public function isPassing(): bool
    {
        return $this->status === SchedulerDoctorStatus::Pass;
    }
}
