<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Doctor;

use Vortos\OpsKit\Gate\GateDisposition;

final readonly class SchedulerDoctorFinding
{
    /** The check's stable id ('C14'), kept for rendering and the JSON report. */
    public string $checkId;

    /**
     * Whether a failure may stop a release is a property of the CHECK, declared once on
     * {@see SchedulerDoctorCheck::disposition()} — not something each finding chooses, and not
     * something a finding can forget to say.
     */
    public function __construct(
        public SchedulerDoctorCheck  $check,
        public SchedulerDoctorStatus $status,
        public string                $summary,
        public string                $detail      = '',
        public string                $remediation = '',
    ) {
        $this->checkId = $check->value;
    }

    public function disposition(): GateDisposition
    {
        return $this->check->disposition();
    }

    public function isFailure(): bool
    {
        return $this->status === SchedulerDoctorStatus::Fail;
    }

    /** A failure that must stop a release; with $strict, advisory failures do too. */
    public function isDeployBlockingFailure(bool $strict = false): bool
    {
        return $this->isFailure() && $this->disposition()->blocksRelease($strict);
    }

    /** A failure describing the running system, reported but never a veto on its own. */
    public function isAdvisoryFailure(): bool
    {
        return $this->isFailure() && $this->disposition() === GateDisposition::Advisory;
    }

    public function isPassing(): bool
    {
        return $this->status === SchedulerDoctorStatus::Pass;
    }
}
