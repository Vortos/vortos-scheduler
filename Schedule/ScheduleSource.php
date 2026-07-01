<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Schedule;

enum ScheduleSource: string
{
    /** Discovered at compile-time via #[Scheduled]; immutable post-deploy. */
    case Static = 'static';

    /** Stored in DB; mutable at runtime via the management API. */
    case Dynamic = 'dynamic';
}
