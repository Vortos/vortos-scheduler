<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Schedule\Policy;

enum OverlapPolicy: string
{
    /**
     * Drop the incoming slot if the prior run for this schedule is still dispatched.
     * Records a skipped_overlap ledger entry. Default and safest choice.
     */
    case Skip = 'skip';

    /**
     * Enqueue anyway; the consumer (CommandBus handler) is responsible for
     * serialization / ordering. Use only when handlers are explicitly concurrent-safe.
     */
    case Queue = 'queue';

    /**
     * No overlap check; always enqueue.
     * Use only for idempotent, stateless handlers.
     */
    case AllowConcurrent = 'allow_concurrent';
}
