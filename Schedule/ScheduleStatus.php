<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Schedule;

enum ScheduleStatus: string
{
    /** Daemon evaluates and fires this schedule. */
    case Active = 'active';

    /** Daemon skips; audited; re-activatable at any time. */
    case Paused = 'paused';

    /** Permanently off; typically set by doctor on misconfigured schedules. */
    case Disabled = 'disabled';
}
