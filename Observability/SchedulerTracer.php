<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Observability;

use Vortos\Tracing\Contract\TracingInterface;

/**
 * Wraps each scheduler fire in a tracing span via the framework {@see TracingInterface} (S8).
 *
 * Constructor accepts `?TracingInterface` — when null (no tracing backend) every call
 * delegates directly to the callable with zero overhead (same behaviour as NoOpTracer).
 *
 * Span attributes follow the Semantic Conventions for scheduled jobs:
 *   - `scheduler.schedule_id`
 *   - `scheduler.slot`
 *   - `scheduler.scheduled_for` (ISO-8601)
 *   - `scheduler.lag_ms`
 *   - `scheduler.tenant_id` (when set)
 */
final class SchedulerTracer
{
    public function __construct(private readonly ?TracingInterface $tracer = null) {}

    /**
     * Wrap a fire dispatch in a span. Returns the callable's return value.
     * On exception: records the error on the span, marks it ERROR, and rethrows.
     */
    public function traceDispatch(
        string $scheduleId,
        string $slot,
        \DateTimeImmutable $scheduledFor,
        int $lagMs,
        ?string $tenantId,
        callable $dispatch,
    ): mixed {
        if ($this->tracer === null) {
            return $dispatch();
        }

        $attributes = [
            'scheduler.schedule_id'   => $scheduleId,
            'scheduler.slot'          => $slot,
            'scheduler.scheduled_for' => $scheduledFor->format(\DateTimeInterface::ATOM),
            'scheduler.lag_ms'        => $lagMs,
        ];

        if ($tenantId !== null) {
            $attributes['scheduler.tenant_id'] = $tenantId;
        }

        $span = $this->tracer->startSpan('scheduler.fire', $attributes);

        try {
            $result = $dispatch();
            $span->setStatus('ok');

            return $result;
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus('error');
            throw $e;
        } finally {
            $span->end();
        }
    }
}
