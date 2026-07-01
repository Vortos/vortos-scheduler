<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Audit;

enum SchedulerAuditEvent: string
{
    // ── Schedule lifecycle mutations ────────────────────────────────────────
    // Called by ScheduleService / operator commands (S9).
    case ScheduleCreated  = 'schedule.created';
    case ScheduleUpdated  = 'schedule.updated';
    case SchedulePaused   = 'schedule.paused';
    case ScheduleResumed  = 'schedule.resumed';
    case ScheduleDeleted  = 'schedule.deleted';
    case ScheduleApproved = 'schedule.approved';

    // ── Fire events ─────────────────────────────────────────────────────────
    // Called by SchedulerDaemon / FireDispatcher.
    case FireDispatched    = 'fire.dispatched';
    case FireSkippedOverlap = 'fire.skipped_overlap';
    case FireMisfired      = 'fire.misfired';
    case FireDropped       = 'fire.dropped';     // beyond max_catchup_age horizon

    // ── Leader election events ───────────────────────────────────────────────
    // Called by SchedulerDaemon on shard lease acquire / lose.
    case LeaderAcquired = 'leader.acquired';
    case LeaderLost     = 'leader.lost';

    // ── Manual fire (operator-triggered, S9) ──────────────────────────────────
    case FireManual = 'fire.manual'; // manually triggered via scheduler:run-now
}
