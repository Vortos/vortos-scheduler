<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Doctor;

use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Doctor\SchedulerDoctorFinding;
use Vortos\Scheduler\Doctor\SchedulerDoctorReport;
use Vortos\Scheduler\Doctor\SchedulerDoctorStatus;

/**
 * @covers \Vortos\Scheduler\Doctor\SchedulerDoctorReport
 */
final class SchedulerDoctorReportTest extends TestCase
{
    private static function pass(string $id = 'C1'): SchedulerDoctorFinding
    {
        return new SchedulerDoctorFinding($id, SchedulerDoctorStatus::Pass, 'OK');
    }

    private static function failure(string $id = 'C1'): SchedulerDoctorFinding
    {
        return new SchedulerDoctorFinding($id, SchedulerDoctorStatus::Fail, 'Broken', 'detail', 'fix it');
    }

    private static function skip(string $id = 'C3'): SchedulerDoctorFinding
    {
        return new SchedulerDoctorFinding($id, SchedulerDoctorStatus::Skip, 'N/A');
    }

    // ── isClear ─────────────────────────────────────────────────────────────

    public function test_is_clear_when_all_pass(): void
    {
        $r = new SchedulerDoctorReport([self::pass('C1'), self::pass('C2')]);
        self::assertTrue($r->isClear());
    }

    public function test_is_clear_when_skipped(): void
    {
        $r = new SchedulerDoctorReport([self::pass('C1'), self::skip('C3')]);
        self::assertTrue($r->isClear());
    }

    public function test_is_not_clear_when_any_fail(): void
    {
        $r = new SchedulerDoctorReport([self::pass('C1'), self::failure('C2')]);
        self::assertFalse($r->isClear());
    }

    public function test_empty_report_is_clear(): void
    {
        $r = new SchedulerDoctorReport([]);
        self::assertTrue($r->isClear());
    }

    // ── exitCode ─────────────────────────────────────────────────────────────

    public function test_exit_code_zero_when_clear(): void
    {
        $r = new SchedulerDoctorReport([self::pass()]);
        self::assertSame(0, $r->exitCode());
    }

    public function test_exit_code_one_when_failure(): void
    {
        $r = new SchedulerDoctorReport([self::failure()]);
        self::assertSame(1, $r->exitCode());
    }

    // ── countByStatus ────────────────────────────────────────────────────────

    public function test_count_by_status(): void
    {
        $r = new SchedulerDoctorReport([
            self::pass('C1'), self::pass('C2'),
            self::failure('C4'), self::failure('C5'),
            self::skip('C3'),
        ]);
        self::assertSame(2, $r->countByStatus(SchedulerDoctorStatus::Pass));
        self::assertSame(2, $r->countByStatus(SchedulerDoctorStatus::Fail));
        self::assertSame(1, $r->countByStatus(SchedulerDoctorStatus::Skip));
    }

    // ── toJson ───────────────────────────────────────────────────────────────

    public function test_to_json_encodes_all_fields(): void
    {
        $r = new SchedulerDoctorReport([
            new SchedulerDoctorFinding('C1', SchedulerDoctorStatus::Pass, 'All OK', '', ''),
            new SchedulerDoctorFinding('C2', SchedulerDoctorStatus::Fail, 'Broken', 'detail', 'fix'),
        ]);

        $decoded = json_decode($r->toJson(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(SchedulerDoctorReport::SCHEMA_VERSION, $decoded['schema_version']);
        self::assertFalse($decoded['clear']);
        self::assertCount(2, $decoded['findings']);
        self::assertSame('C1', $decoded['findings'][0]['check_id']);
        self::assertSame('pass', $decoded['findings'][0]['status']);
        self::assertSame('C2', $decoded['findings'][1]['check_id']);
        self::assertSame('fail', $decoded['findings'][1]['status']);
        self::assertSame('detail', $decoded['findings'][1]['detail']);
        self::assertSame('fix', $decoded['findings'][1]['remediation']);
    }

    public function test_to_json_is_valid_json(): void
    {
        $r = new SchedulerDoctorReport([self::pass()]);
        self::assertIsString($r->toJson());
        json_decode($r->toJson(), true, 512, JSON_THROW_ON_ERROR);
        $this->addToAssertionCount(1);
    }

    public function test_schema_version_constant_is_1(): void
    {
        self::assertSame(1, SchedulerDoctorReport::SCHEMA_VERSION);
    }
}
