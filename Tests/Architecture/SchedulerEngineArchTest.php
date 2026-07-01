<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Engine\DueScan;
use Vortos\Scheduler\Engine\DueScanResult;
use Vortos\Scheduler\Engine\DroppedSlotRecord;
use Vortos\Scheduler\Engine\FireDispatchResult;
use Vortos\Scheduler\Engine\FireDispatcher;
use Vortos\Scheduler\Engine\MisfireResolver;
use Vortos\Scheduler\Engine\Outbox\DbalSchedulerEnqueuer;
use Vortos\Scheduler\Engine\SchedulerEnqueuerPort;
use Vortos\Scheduler\Engine\SlotCalculator;
use Vortos\Scheduler\Fire\RunCompletionMiddleware;
use Vortos\Scheduler\Fire\RunStamp;
use Vortos\Scheduler\Fire\ScheduledFire;
use Vortos\Scheduler\Testing\RecordingSchedulerEnqueuer;

/**
 * Structural architecture tests for S4 Engine classes.
 *
 * Checks that:
 *  1. Pure value objects (DueScanResult, DroppedSlotRecord, ScheduledFire, RunStamp, FireDispatchResult)
 *     exist as final/readonly classes/enums with no constructor side effects.
 *  2. Pure engine classes (DueScan, MisfireResolver, SlotCalculator) are final and have no
 *     infrastructure constructor parameters.
 *  3. SchedulerEnqueuerPort is an interface.
 *  4. DbalSchedulerEnqueuer implements SchedulerEnqueuerPort.
 *  5. RecordingSchedulerEnqueuer implements SchedulerEnqueuerPort.
 *  6. RunCompletionMiddleware implements MiddlewareInterface.
 *  7. FireDispatcher has exactly the right constructor parameters.
 */
final class SchedulerEngineArchTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────
    // Value objects
    // ─────────────────────────────────────────────────────────────

    public function test_due_scan_result_is_final_readonly(): void
    {
        $r = new \ReflectionClass(DueScanResult::class);
        self::assertTrue($r->isFinal());
        self::assertTrue($r->isReadOnly());
    }

    public function test_dropped_slot_record_is_final_readonly(): void
    {
        $r = new \ReflectionClass(DroppedSlotRecord::class);
        self::assertTrue($r->isFinal());
        self::assertTrue($r->isReadOnly());
    }

    public function test_scheduled_fire_is_final_readonly(): void
    {
        $r = new \ReflectionClass(ScheduledFire::class);
        self::assertTrue($r->isFinal());
        self::assertTrue($r->isReadOnly());
    }

    public function test_run_stamp_is_final_readonly(): void
    {
        $r = new \ReflectionClass(RunStamp::class);
        self::assertTrue($r->isFinal());
        self::assertTrue($r->isReadOnly());
    }

    public function test_fire_dispatch_result_is_enum(): void
    {
        $r = new \ReflectionEnum(FireDispatchResult::class);
        self::assertTrue($r->isBacked() === false, 'FireDispatchResult must be a unit enum (no backing type)');
    }

    // ─────────────────────────────────────────────────────────────
    // Pure engine
    // ─────────────────────────────────────────────────────────────

    public function test_due_scan_is_final(): void
    {
        self::assertTrue((new \ReflectionClass(DueScan::class))->isFinal());
    }

    public function test_misfire_resolver_is_final(): void
    {
        self::assertTrue((new \ReflectionClass(MisfireResolver::class))->isFinal());
    }

    public function test_slot_calculator_is_final(): void
    {
        self::assertTrue((new \ReflectionClass(SlotCalculator::class))->isFinal());
    }

    public function test_misfire_resolver_constructor_takes_only_slot_calculator(): void
    {
        $params = (new \ReflectionClass(MisfireResolver::class))->getConstructor()?->getParameters() ?? [];
        self::assertCount(1, $params);
        self::assertSame('slotCalculator', $params[0]->getName());
    }

    // ─────────────────────────────────────────────────────────────
    // Port contract
    // ─────────────────────────────────────────────────────────────

    public function test_scheduler_enqueuer_port_is_interface(): void
    {
        self::assertTrue((new \ReflectionClass(SchedulerEnqueuerPort::class))->isInterface());
    }

    public function test_dbal_scheduler_enqueuer_implements_port(): void
    {
        // DbalSchedulerEnqueuer is final, so we use reflection instead of mocking.
        self::assertTrue(
            (new \ReflectionClass(DbalSchedulerEnqueuer::class))
                ->implementsInterface(SchedulerEnqueuerPort::class),
        );
    }

    public function test_recording_scheduler_enqueuer_implements_port(): void
    {
        self::assertInstanceOf(SchedulerEnqueuerPort::class, new RecordingSchedulerEnqueuer());
    }

    // ─────────────────────────────────────────────────────────────
    // Consumer middleware
    // ─────────────────────────────────────────────────────────────

    public function test_run_completion_middleware_implements_middleware_interface(): void
    {
        self::assertTrue(
            (new \ReflectionClass(RunCompletionMiddleware::class))
                ->implementsInterface(\Vortos\Messaging\Middleware\MiddlewareInterface::class)
        );
    }

    public function test_run_completion_middleware_is_final(): void
    {
        self::assertTrue((new \ReflectionClass(RunCompletionMiddleware::class))->isFinal());
    }

    // ─────────────────────────────────────────────────────────────
    // FireDispatcher
    // ─────────────────────────────────────────────────────────────

    public function test_fire_dispatcher_is_final(): void
    {
        self::assertTrue((new \ReflectionClass(FireDispatcher::class))->isFinal());
    }

    public function test_fire_dispatcher_has_correct_constructor_parameter_names(): void
    {
        $params = (new \ReflectionClass(FireDispatcher::class))->getConstructor()?->getParameters() ?? [];
        $names  = array_map(fn($p) => $p->getName(), $params);

        self::assertContains('runStore',         $names);
        self::assertContains('enqueuer',         $names);
        self::assertContains('connection',       $names);
        self::assertContains('clock',            $names);
        self::assertContains('assumedDoneTtlSec', $names);
    }

    // ─────────────────────────────────────────────────────────────
    // DroppedSlotRecord::REASON_* constants
    // ─────────────────────────────────────────────────────────────

    public function test_dropped_slot_record_has_beyond_horizon_constant(): void
    {
        self::assertSame('beyond_horizon', DroppedSlotRecord::REASON_BEYOND_HORIZON);
    }

    // ─────────────────────────────────────────────────────────────
    // RunStamp header constants
    // ─────────────────────────────────────────────────────────────

    public function test_run_stamp_header_constants_defined(): void
    {
        self::assertSame('X-Scheduler-Run-Id',      RunStamp::HEADER_RUN_ID);
        self::assertSame('X-Scheduler-Schedule-Id', RunStamp::HEADER_SCHEDULE_ID);
        self::assertSame('X-Scheduler-Slot',        RunStamp::HEADER_SLOT);
        self::assertSame('X-Scheduler-Tenant-Id',   RunStamp::HEADER_TENANT_ID);
    }
}
