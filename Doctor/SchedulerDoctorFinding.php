<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Doctor;

final readonly class SchedulerDoctorFinding
{
    public function __construct(
        public string               $checkId,
        public SchedulerDoctorStatus $status,
        public string               $summary,
        public string               $detail      = '',
        public string               $remediation = '',
    ) {}

    public function isFailure(): bool
    {
        return $this->status === SchedulerDoctorStatus::Fail;
    }

    public function isPassing(): bool
    {
        return $this->status === SchedulerDoctorStatus::Pass;
    }
}
