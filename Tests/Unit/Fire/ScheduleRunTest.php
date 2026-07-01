<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Fire;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Fire\IdempotencyKey;
use Vortos\Scheduler\Fire\RunState;
use Vortos\Scheduler\Fire\ScheduleRun;
use Vortos\Scheduler\Schedule\ScheduleId;

final class ScheduleRunTest extends TestCase
{
    public function test_construction_stores_all_fields(): void
    {
        $id        = ScheduleId::generate();
        $now       = new DateTimeImmutable('2026-07-01T10:00:00Z');
        $key       = IdempotencyKey::fromSlotKey($id->toString() . ':slot-1');

        $run = new ScheduleRun(
            runId:        $key->value,
            scheduleId:   $id,
            tenantId:     'tenant-x',
            slot:         'slot-1',
            scheduledFor: $now,
            dispatchedAt: $now,
            state:        RunState::Dispatched,
            attempt:      1,
        );

        self::assertSame($key->value, $run->runId);
        self::assertTrue($id->equals($run->scheduleId));
        self::assertSame('tenant-x', $run->tenantId);
        self::assertSame('slot-1', $run->slot);
        self::assertEquals($now, $run->scheduledFor);
        self::assertEquals($now, $run->dispatchedAt);
        self::assertSame(RunState::Dispatched, $run->state);
        self::assertSame(1, $run->attempt);
    }

    public function test_attempt_defaults_to_1(): void
    {
        $id  = ScheduleId::generate();
        $key = IdempotencyKey::fromSlotKey($id->toString() . ':default-attempt');

        $run = new ScheduleRun(
            runId:        $key->value,
            scheduleId:   $id,
            tenantId:     null,
            slot:         'default-attempt',
            scheduledFor: new DateTimeImmutable(),
            dispatchedAt: new DateTimeImmutable(),
            state:        RunState::Dispatched,
        );

        self::assertSame(1, $run->attempt);
    }

    public function test_null_tenant_id_accepted_for_system_schedules(): void
    {
        $id  = ScheduleId::generate();
        $key = IdempotencyKey::fromSlotKey($id->toString() . ':sys-slot');

        $run = new ScheduleRun(
            runId:        $key->value,
            scheduleId:   $id,
            tenantId:     null,
            slot:         'sys-slot',
            scheduledFor: new DateTimeImmutable(),
            dispatchedAt: new DateTimeImmutable(),
            state:        RunState::Dispatched,
        );

        self::assertNull($run->tenantId);
    }

    public function test_is_terminal_returns_false_for_dispatched(): void
    {
        $run = $this->make(RunState::Dispatched);

        self::assertFalse($run->isTerminal());
    }

    public function test_is_terminal_returns_true_for_completed(): void
    {
        self::assertTrue($this->make(RunState::Completed)->isTerminal());
    }

    public function test_is_terminal_returns_true_for_failed(): void
    {
        self::assertTrue($this->make(RunState::Failed)->isTerminal());
    }

    private function make(RunState $state): ScheduleRun
    {
        $id  = ScheduleId::generate();
        $key = IdempotencyKey::fromSlotKey($id->toString() . ':test');

        return new ScheduleRun(
            runId:        $key->value,
            scheduleId:   $id,
            tenantId:     'tenant-test',
            slot:         'test',
            scheduledFor: new DateTimeImmutable(),
            dispatchedAt: new DateTimeImmutable(),
            state:        $state,
        );
    }
}
