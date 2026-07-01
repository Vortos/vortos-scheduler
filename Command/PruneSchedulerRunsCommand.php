<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Command;

use Vortos\Domain\Command\CommandInterface;
use Vortos\Scheduler\Security\Attribute\SchedulableCommand;

/**
 * Fired daily by Registry/PruneSchedulerRunsSchedule (S12/auto-prune).
 *
 * Carries no payload — PruneSchedulerRunsHandler reads current retention config
 * and overrides at execution time, not from a snapshot taken when the schedule
 * was registered. Exactly-once dispatch is already guaranteed upstream by the
 * fire-ledger's UNIQUE(tenant_id, schedule_id, slot) constraint, so no CQRS-bus-
 * level idempotency key is needed here.
 */
#[SchedulableCommand]
final readonly class PruneSchedulerRunsCommand implements CommandInterface
{
    public function idempotencyKey(): ?string
    {
        return null;
    }
}
