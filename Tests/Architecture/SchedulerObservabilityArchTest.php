<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Observability\DeadManDetector;
use Vortos\Scheduler\Observability\SchedulerMetricDefinitions;
use Vortos\Scheduler\Observability\SchedulerMetrics;
use Vortos\Scheduler\Observability\SchedulerTracer;

/**
 * Architecture guardrails for the S8 observability layer.
 */
final class SchedulerObservabilityArchTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────
    // A: SchedulerMetrics is defensive — every metric call is try/catch
    // ─────────────────────────────────────────────────────────────

    public function test_scheduler_metrics_wraps_every_emit_in_try_catch(): void
    {
        $src      = $this->srcOf(SchedulerMetrics::class);
        $tryCount = substr_count($src, 'try {');

        // Count public metric-emitting methods (recordFireResult, recordMisfire, etc.)
        // Each public method that touches $this->metrics should have a try/catch
        $reflection  = new \ReflectionClass(SchedulerMetrics::class);
        $publicCount = count(array_filter(
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
            fn (\ReflectionMethod $m) => !$m->isConstructor() && str_starts_with($m->getName(), 'record'),
        ));

        self::assertGreaterThanOrEqual(
            $publicCount,
            $tryCount,
            'Every record*() method in SchedulerMetrics must wrap the emit in try/catch to never throw.',
        );
    }

    public function test_scheduler_metrics_never_throws_unguarded(): void
    {
        $src = $this->srcOf(SchedulerMetrics::class);

        // No 'throw' statement anywhere in SchedulerMetrics
        self::assertStringNotContainsString(
            'throw new',
            $src,
            'SchedulerMetrics must never throw — it is a best-effort metrics sink.',
        );
        self::assertStringNotContainsString(
            'throw $',
            $src,
        );
    }

    // ─────────────────────────────────────────────────────────────
    // B: SchedulerMetrics accepts null (graceful no-op)
    // ─────────────────────────────────────────────────────────────

    public function test_scheduler_metrics_constructor_accepts_nullable_metrics(): void
    {
        $reflection  = new \ReflectionClass(SchedulerMetrics::class);
        $constructor = $reflection->getConstructor();

        self::assertNotNull($constructor);
        $params = $constructor->getParameters();
        self::assertCount(1, $params);
        self::assertTrue($params[0]->allowsNull(), 'SchedulerMetrics constructor must accept ?MetricsInterface');
    }

    // ─────────────────────────────────────────────────────────────
    // C: SchedulerTracer accepts null (passthrough when no OTel)
    // ─────────────────────────────────────────────────────────────

    public function test_scheduler_tracer_constructor_accepts_nullable_tracer(): void
    {
        $reflection  = new \ReflectionClass(SchedulerTracer::class);
        $constructor = $reflection->getConstructor();

        self::assertNotNull($constructor);
        $params = $constructor->getParameters();
        self::assertCount(1, $params);
        self::assertTrue($params[0]->allowsNull(), 'SchedulerTracer constructor must accept ?TracingInterface');
    }

    public function test_scheduler_tracer_rethrows_dispatch_exceptions(): void
    {
        $src = $this->srcOf(SchedulerTracer::class);

        // The exception must be rethrown — span records it, then caller still sees it
        self::assertStringContainsString(
            'throw $e',
            $src,
            'SchedulerTracer must rethrow dispatch exceptions after recording them on the span.',
        );
    }

    public function test_scheduler_tracer_always_ends_span(): void
    {
        $src = $this->srcOf(SchedulerTracer::class);

        // Span must be ended in a finally block
        self::assertStringContainsString('finally {', $src);
        self::assertStringContainsString('->end()', $src);
    }

    // ─────────────────────────────────────────────────────────────
    // D: DeadManDetector never throws (check() is non-throwing)
    // ─────────────────────────────────────────────────────────────

    public function test_dead_man_detector_check_never_throws_unguarded(): void
    {
        $src = $this->srcOf(DeadManDetector::class);

        // The check() method and helpers catch all Throwable — must not re-throw
        // Count catch blocks: should be >= 3 (bulk query, per-schedule, per-alert)
        $catchCount = substr_count($src, 'catch (\\Throwable');

        self::assertGreaterThanOrEqual(3, $catchCount,
            'DeadManDetector must have at least 3 Throwable catch blocks: ' .
            'bulk-query, per-schedule loop, and per-alert dispatch.',
        );
    }

    // ─────────────────────────────────────────────────────────────
    // E: SchedulerMetricDefinitions implements the framework tag interface
    // ─────────────────────────────────────────────────────────────

    public function test_metric_definitions_implements_provider_interface(): void
    {
        self::assertTrue(
            is_a(SchedulerMetricDefinitions::class, \Vortos\Metrics\Definition\MetricDefinitionProviderInterface::class, true),
            'SchedulerMetricDefinitions must implement MetricDefinitionProviderInterface.',
        );
    }

    // ─────────────────────────────────────────────────────────────
    // F: Metrics/Tracing classes do not import DBAL
    // ─────────────────────────────────────────────────────────────

    public function test_scheduler_metrics_has_no_dbal_imports(): void
    {
        $src = $this->srcOf(SchedulerMetrics::class);

        self::assertStringNotContainsString(
            'Doctrine\DBAL',
            $src,
            'SchedulerMetrics must not import DBAL — it is a pure metrics emitter.',
        );
    }

    public function test_scheduler_tracer_has_no_dbal_imports(): void
    {
        $src = $this->srcOf(SchedulerTracer::class);

        self::assertStringNotContainsString(
            'Doctrine\DBAL',
            $src,
            'SchedulerTracer must not import DBAL.',
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Helper
    // ─────────────────────────────────────────────────────────────

    private function srcOf(string $fqcn): string
    {
        $reflector = new \ReflectionClass($fqcn);
        $path      = $reflector->getFileName();

        self::assertNotFalse($path, "Could not resolve file for {$fqcn}");

        $src = file_get_contents($path);
        self::assertNotFalse($src, "Could not read file at {$path}");

        return $src;
    }
}
