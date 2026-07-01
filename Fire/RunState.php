<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Fire;

/**
 * State machine for a single scheduled fire's lifecycle in the run-ledger.
 *
 * Transitions enforced by DbalScheduleRunStore::transitionRunState():
 *   dispatched → completed ✓
 *   dispatched → failed    ✓
 *   completed  → *         ✗ (terminal — command handler finished)
 *   failed     → *         ✗ (terminal — command handler threw)
 */
enum RunState: string
{
    case Dispatched = 'dispatched';
    case Completed  = 'completed';
    case Failed     = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Dispatched => [self::Completed, self::Failed],
            self::Completed, self::Failed => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
