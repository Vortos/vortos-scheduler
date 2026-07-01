<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Doctor;

use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Doctor\SchedulerDoctorFinding;
use Vortos\Scheduler\Doctor\SchedulerDoctorStatus;

/**
 * @covers \Vortos\Scheduler\Doctor\SchedulerDoctorFinding
 */
final class SchedulerDoctorFindingTest extends TestCase
{
    public function test_pass_finding_is_passing(): void
    {
        $f = new SchedulerDoctorFinding('C1', SchedulerDoctorStatus::Pass, 'All good.');
        self::assertTrue($f->isPassing());
        self::assertFalse($f->isFailure());
    }

    public function test_fail_finding_is_failure(): void
    {
        $f = new SchedulerDoctorFinding('C1', SchedulerDoctorStatus::Fail, 'Something broken.');
        self::assertTrue($f->isFailure());
        self::assertFalse($f->isPassing());
    }

    public function test_skip_finding_is_neither_pass_nor_fail(): void
    {
        $f = new SchedulerDoctorFinding('C3', SchedulerDoctorStatus::Skip, 'Not applicable.');
        self::assertFalse($f->isFailure());
        self::assertFalse($f->isPassing());
    }

    public function test_all_fields_are_stored(): void
    {
        $f = new SchedulerDoctorFinding('C5', SchedulerDoctorStatus::Fail, 'Missing table.', 'detail text', 'run migrations');
        self::assertSame('C5', $f->checkId);
        self::assertSame(SchedulerDoctorStatus::Fail, $f->status);
        self::assertSame('Missing table.', $f->summary);
        self::assertSame('detail text', $f->detail);
        self::assertSame('run migrations', $f->remediation);
    }

    public function test_defaults_detail_and_remediation_to_empty_string(): void
    {
        $f = new SchedulerDoctorFinding('C1', SchedulerDoctorStatus::Pass, 'OK');
        self::assertSame('', $f->detail);
        self::assertSame('', $f->remediation);
    }

    public function test_is_readonly(): void
    {
        $f = new SchedulerDoctorFinding('C1', SchedulerDoctorStatus::Pass, 'OK');
        self::assertTrue((new \ReflectionClass($f))->isReadOnly());
    }
}
