<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Doctor;

enum SchedulerDoctorStatus: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Skip = 'skip';
}
