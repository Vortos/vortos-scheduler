<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Store\Exception;

use Vortos\Scheduler\Fire\RunState;

/**
 * Thrown when transitionRunState() receives a state change that violates the
 * run-state machine: e.g. completed → failed, or failed → completed.
 *
 * Terminal states (completed, failed) cannot be transitioned again.
 */
final class InvalidRunStateTransitionException extends \DomainException
{
    public function __construct(string $runId, RunState $from, RunState $to)
    {
        parent::__construct(
            "Invalid run-state transition for run '{$runId}': " .
            "{$from->value} → {$to->value}. " .
            "Allowed transitions from {$from->value}: [" .
            implode(', ', array_map(fn (RunState $s) => $s->value, $from->allowedTransitions())) .
            '].',
        );
    }
}
