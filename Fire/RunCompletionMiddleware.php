<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Fire;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Envelope;
use Vortos\Messaging\Bus\Stamp\HeadersStamp;
use Vortos\Messaging\Middleware\MiddlewareInterface;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;

/**
 * Consumer middleware that transitions the fire-ledger run state to Completed
 * when a scheduled command handler succeeds.
 *
 * MUST be tagged as a consumer middleware (vortos.middleware) with priority 50
 * (outermost among user-defined middlewares). This ensures it wraps the inner
 * middleware chain and executes ITS state transition inside the same DB transaction
 * opened by TransactionalMiddleware.
 *
 * HOW IT WORKS:
 *   On entry: reads RunStamp headers from HeadersStamp. If no scheduler headers
 *             are present, the envelope is not a scheduler fire — pass straight through.
 *   On exit (success): transitions the ledger row from Dispatched → Completed.
 *                      This runs INSIDE TransactionalMiddleware's transaction, so the
 *                      state flip is atomic with the command handler's DB writes.
 *   On exit (exception): the exception propagates unmodified. TransactionalMiddleware
 *                        catches it and rolls back. The run stays in Dispatched state.
 *                        The SchedulerDaemon (S5) handles the Failed transition in a
 *                        fresh transaction after catching the exception.
 *
 * PRIORITY NOTE:
 *   MiddlewareCompilerPass sorts tagged middlewares by priority DESC (highest = outermost).
 *   Priority 50 makes this the outermost tagged middleware — it wraps all lower-priority
 *   middlewares and sees the final outcome (success or exception) from the full chain.
 *   TransactionalMiddleware is a core middleware (not tagged), registered before all
 *   tagged middlewares; this middleware runs INSIDE its transaction.
 */
final class RunCompletionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ScheduleRunStoreInterface $runStore,
        private readonly ClockInterface            $clock,
    ) {}

    public function handle(Envelope $envelope, callable $next): Envelope
    {
        $headers = $envelope->last(HeadersStamp::class)?->headers ?? [];
        $stamp   = RunStamp::fromHeaders($headers);

        if ($stamp === null) {
            return $next($envelope);
        }

        // Execute the full inner chain (TransactionalMiddleware → handler → ...)
        // inside the current transaction context.
        $result = $next($envelope);

        // Handler succeeded — transition run state to Completed inside the same TX.
        $this->runStore->transitionRunState(
            $stamp->runId,
            RunState::Completed,
            $this->clock->now(),
        );

        return $result;
    }
}
