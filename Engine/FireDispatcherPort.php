<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Engine;

use Vortos\Scheduler\Fire\ScheduledFire;
use Vortos\Scheduler\Schedule\Schedule;

/**
 * Port seam for the fire dispatcher, enabling testability without
 * requiring a full DBAL connection in unit tests.
 */
interface FireDispatcherPort
{
    public function dispatch(ScheduledFire $fire, Schedule $schedule): FireDispatchResult;
}
