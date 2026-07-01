<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Observability;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Observability\SchedulerTracer;
use Vortos\Tracing\Contract\SpanInterface;
use Vortos\Tracing\Contract\TracingInterface;
use Vortos\Tracing\NoOpTracer;

final class SchedulerTracerTest extends TestCase
{
    private DateTimeImmutable $scheduledFor;

    protected function setUp(): void
    {
        $this->scheduledFor = new DateTimeImmutable('2026-07-01T02:00:00Z');
    }

    // ── Null tracer (passthrough) ─────────────────────────────────────────────

    public function test_null_tracer_calls_dispatch_directly(): void
    {
        $tracer = new SchedulerTracer(null);
        $called = false;

        $result = $tracer->traceDispatch('s', 'slot', $this->scheduledFor, 0, null, function () use (&$called) {
            $called = true;

            return 'result';
        });

        self::assertTrue($called);
        self::assertSame('result', $result);
    }

    public function test_null_tracer_propagates_exception(): void
    {
        $tracer = new SchedulerTracer(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('dispatch error');

        $tracer->traceDispatch('s', 'slot', $this->scheduledFor, 0, null, function () {
            throw new \RuntimeException('dispatch error');
        });
    }

    // ── With tracer ───────────────────────────────────────────────────────────

    public function test_opens_span_with_correct_name(): void
    {
        $spy    = new SpyTracer();
        $tracer = new SchedulerTracer($spy);

        $tracer->traceDispatch('sched-1', 'slot-1', $this->scheduledFor, 250, null, fn () => null);

        self::assertSame('scheduler.fire', $spy->lastSpanName);
    }

    public function test_sets_schedule_id_attribute(): void
    {
        $spy    = new SpyTracer();
        $tracer = new SchedulerTracer($spy);

        $tracer->traceDispatch('sched-42', 'slot', $this->scheduledFor, 0, null, fn () => null);

        self::assertSame('sched-42', $spy->lastAttributes['scheduler.schedule_id']);
    }

    public function test_sets_slot_attribute(): void
    {
        $spy    = new SpyTracer();
        $tracer = new SchedulerTracer($spy);

        $tracer->traceDispatch('s', 'my-slot', $this->scheduledFor, 0, null, fn () => null);

        self::assertSame('my-slot', $spy->lastAttributes['scheduler.slot']);
    }

    public function test_sets_lag_ms_attribute(): void
    {
        $spy    = new SpyTracer();
        $tracer = new SchedulerTracer($spy);

        $tracer->traceDispatch('s', 'slot', $this->scheduledFor, 1500, null, fn () => null);

        self::assertSame(1500, $spy->lastAttributes['scheduler.lag_ms']);
    }

    public function test_sets_tenant_id_when_provided(): void
    {
        $spy    = new SpyTracer();
        $tracer = new SchedulerTracer($spy);

        $tracer->traceDispatch('s', 'slot', $this->scheduledFor, 0, 'tenant-7', fn () => null);

        self::assertSame('tenant-7', $spy->lastAttributes['scheduler.tenant_id']);
    }

    public function test_omits_tenant_id_when_null(): void
    {
        $spy    = new SpyTracer();
        $tracer = new SchedulerTracer($spy);

        $tracer->traceDispatch('s', 'slot', $this->scheduledFor, 0, null, fn () => null);

        self::assertArrayNotHasKey('scheduler.tenant_id', $spy->lastAttributes);
    }

    public function test_sets_status_ok_on_success(): void
    {
        $spy    = new SpyTracer();
        $tracer = new SchedulerTracer($spy);

        $tracer->traceDispatch('s', 'slot', $this->scheduledFor, 0, null, fn () => 'ok');

        self::assertSame('ok', $spy->lastSpan->lastStatus);
        self::assertTrue($spy->lastSpan->ended);
    }

    public function test_sets_status_error_and_records_exception_on_throw(): void
    {
        $spy    = new SpyTracer();
        $tracer = new SchedulerTracer($spy);
        $ex     = new \RuntimeException('fire failed');

        try {
            $tracer->traceDispatch('s', 'slot', $this->scheduledFor, 0, null, fn () => throw $ex);
        } catch (\RuntimeException) {
        }

        self::assertSame('error', $spy->lastSpan->lastStatus);
        self::assertSame($ex, $spy->lastSpan->lastException);
        self::assertTrue($spy->lastSpan->ended);
    }

    public function test_span_always_ended_even_on_exception(): void
    {
        $spy    = new SpyTracer();
        $tracer = new SchedulerTracer($spy);

        try {
            $tracer->traceDispatch('s', 'slot', $this->scheduledFor, 0, null, fn () => throw new \LogicException('oops'));
        } catch (\LogicException) {
        }

        self::assertTrue($spy->lastSpan->ended, 'Span must be ended even when dispatch throws');
    }

    public function test_exception_is_rethrown_after_recording(): void
    {
        $spy    = new SpyTracer();
        $tracer = new SchedulerTracer($spy);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('should rethrow');

        $tracer->traceDispatch('s', 'slot', $this->scheduledFor, 0, null, function () {
            throw new \RuntimeException('should rethrow');
        });
    }

    public function test_returns_dispatch_callable_result(): void
    {
        $spy    = new SpyTracer();
        $tracer = new SchedulerTracer($spy);

        $result = $tracer->traceDispatch('s', 'slot', $this->scheduledFor, 0, null, fn () => 'the-value');

        self::assertSame('the-value', $result);
    }

    public function test_noop_tracer_works_as_backend(): void
    {
        $tracer = new SchedulerTracer(new NoOpTracer());
        $called = false;

        $tracer->traceDispatch('s', 'slot', $this->scheduledFor, 0, null, function () use (&$called) {
            $called = true;
        });

        self::assertTrue($called);
    }
}

// ── Test doubles ──────────────────────────────────────────────────────────────

final class SpySpan implements SpanInterface
{
    public bool $ended        = false;
    public ?string $lastStatus = null;
    public ?\Throwable $lastException = null;

    public function setStatus(string $status): void
    {
        $this->lastStatus = $status;
    }

    public function recordException(\Throwable $e): void
    {
        $this->lastException = $e;
    }

    public function end(): void
    {
        $this->ended = true;
    }

    public function addAttribute(string $key, mixed $value): void {}
}

final class SpyTracer implements TracingInterface
{
    public ?string $lastSpanName   = null;
    public array $lastAttributes   = [];
    public SpySpan $lastSpan;

    public function __construct()
    {
        $this->lastSpan = new SpySpan();
    }

    public function startSpan(string $name, array $attributes = []): SpanInterface
    {
        $this->lastSpanName   = $name;
        $this->lastAttributes = $attributes;
        $this->lastSpan       = new SpySpan();

        return $this->lastSpan;
    }

    public function injectHeaders(array &$headers): void {}
    public function extractContext(array $headers): void {}
    public function setBaggageItem(string $key, string $value): void {}
    public function baggageItem(string $key): ?string { return null; }
    public function baggage(): array { return []; }
    public function currentCorrelationId(): ?string { return null; }
}
