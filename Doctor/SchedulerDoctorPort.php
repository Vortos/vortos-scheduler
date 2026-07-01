<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Doctor;

/**
 * PORT — the minimal interface through which SchedulerPreflightCheck accesses the doctor.
 *
 * Extracting this interface lets callers inject a stub in unit tests without
 * requiring a real database connection or lease backend.
 */
interface SchedulerDoctorPort
{
    public function run(): SchedulerDoctorReport;
}
