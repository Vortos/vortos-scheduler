<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Fire;

use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Fire\RunStamp;

final class RunStampTest extends TestCase
{
    public function test_to_headers_includes_all_fields_for_tenant_fire(): void
    {
        $stamp = new RunStamp(
            runId:      str_repeat('a', 64),
            scheduleId: 'sched-uuid',
            slot:       'sched-uuid:2026-07-01T10:00:00+00:00',
            tenantId:   'tenant-a',
        );

        $headers = $stamp->toHeaders();

        self::assertSame(str_repeat('a', 64), $headers[RunStamp::HEADER_RUN_ID]);
        self::assertSame('sched-uuid', $headers[RunStamp::HEADER_SCHEDULE_ID]);
        self::assertSame('sched-uuid:2026-07-01T10:00:00+00:00', $headers[RunStamp::HEADER_SLOT]);
        self::assertSame('tenant-a', $headers[RunStamp::HEADER_TENANT_ID]);
    }

    public function test_to_headers_omits_tenant_id_when_null(): void
    {
        $stamp = new RunStamp(
            runId:      str_repeat('b', 64),
            scheduleId: 'sched-uuid',
            slot:       'sched-uuid:2026-07-01T10:00:00+00:00',
            tenantId:   null,
        );

        $headers = $stamp->toHeaders();

        self::assertArrayNotHasKey(RunStamp::HEADER_TENANT_ID, $headers);
    }

    public function test_from_headers_round_trips(): void
    {
        $original = new RunStamp(
            runId:      str_repeat('c', 64),
            scheduleId: 'orig-sched',
            slot:       'orig-sched:2026-07-01T11:00:00+00:00',
            tenantId:   'tenant-b',
        );

        $reconstructed = RunStamp::fromHeaders($original->toHeaders());

        self::assertNotNull($reconstructed);
        self::assertSame($original->runId, $reconstructed->runId);
        self::assertSame($original->scheduleId, $reconstructed->scheduleId);
        self::assertSame($original->slot, $reconstructed->slot);
        self::assertSame($original->tenantId, $reconstructed->tenantId);
    }

    public function test_from_headers_returns_null_when_no_run_id(): void
    {
        self::assertNull(RunStamp::fromHeaders([]));
        self::assertNull(RunStamp::fromHeaders([RunStamp::HEADER_RUN_ID => '']));
    }

    public function test_from_headers_null_tenant_when_missing(): void
    {
        $headers = [
            RunStamp::HEADER_RUN_ID      => str_repeat('d', 64),
            RunStamp::HEADER_SCHEDULE_ID => 'sched',
            RunStamp::HEADER_SLOT        => 'sched:2026-01-01T00:00:00+00:00',
        ];

        $stamp = RunStamp::fromHeaders($headers);

        self::assertNotNull($stamp);
        self::assertNull($stamp->tenantId);
    }

    public function test_from_headers_null_tenant_when_empty_string(): void
    {
        $headers = [
            RunStamp::HEADER_RUN_ID      => str_repeat('e', 64),
            RunStamp::HEADER_SCHEDULE_ID => 'sched',
            RunStamp::HEADER_SLOT        => 'sched:2026-01-01T00:00:00+00:00',
            RunStamp::HEADER_TENANT_ID   => '',
        ];

        $stamp = RunStamp::fromHeaders($headers);

        self::assertNotNull($stamp);
        self::assertNull($stamp->tenantId);
    }
}
