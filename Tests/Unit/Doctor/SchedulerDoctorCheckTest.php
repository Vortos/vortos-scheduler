<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Doctor;

use PHPUnit\Framework\TestCase;
use Vortos\OpsKit\Gate\GateDisposition;
use Vortos\Scheduler\Doctor\SchedulerDoctorCheck;
use Vortos\Scheduler\Doctor\SchedulerDoctorFinding;
use Vortos\Scheduler\Doctor\SchedulerDoctorStatus;

final class SchedulerDoctorCheckTest extends TestCase
{
    /**
     * The runtime-state checks. Changing this list is a decision about what may veto a release, so it
     * is spelled out here rather than derived: reclassifying a check must be a visible edit to a test.
     *
     * C14 and C15 are here because each once refused the release that cured it.
     */
    private const ADVISORY = ['C10', 'C11', 'C12', 'C14', 'C15'];

    public function test_exactly_the_runtime_state_checks_are_advisory(): void
    {
        $advisory = array_values(array_map(
            static fn (SchedulerDoctorCheck $c): string => $c->value,
            array_filter(SchedulerDoctorCheck::cases(), static fn (SchedulerDoctorCheck $c): bool => $c->disposition() === GateDisposition::Advisory),
        ));

        self::assertSame(self::ADVISORY, $advisory);
    }

    public function test_every_check_declares_a_title_and_a_disposition(): void
    {
        foreach (SchedulerDoctorCheck::cases() as $check) {
            self::assertNotSame('', $check->title(), $check->value);
            self::assertInstanceOf(GateDisposition::class, $check->disposition());
        }
    }

    public function test_the_doctor_emits_exactly_one_finding_per_declared_check(): void
    {
        // The doctor's run() list and this enum must not drift: a check emitted but not declared cannot
        // be constructed, and a check declared but never emitted is a silent gap.
        $source = (string) file_get_contents(__DIR__ . '/../../../Doctor/SchedulerDoctor.php');

        foreach (SchedulerDoctorCheck::cases() as $check) {
            self::assertMatchesRegularExpression(
                '/SchedulerDoctorCheck::' . $check->name . '\b/',
                $source,
                sprintf('%s is declared but SchedulerDoctor never reports it', $check->value),
            );
        }
    }

    public function test_a_finding_takes_its_gate_from_its_check(): void
    {
        $overdue = new SchedulerDoctorFinding(SchedulerDoctorCheck::C14, SchedulerDoctorStatus::Fail, 'overdue');
        $broken  = new SchedulerDoctorFinding(SchedulerDoctorCheck::C3, SchedulerDoctorStatus::Fail, 'not allowlisted');
        $passing = new SchedulerDoctorFinding(SchedulerDoctorCheck::C3, SchedulerDoctorStatus::Pass, 'fine');

        self::assertSame('C14', $overdue->checkId);
        self::assertTrue($overdue->isAdvisoryFailure());
        self::assertFalse($overdue->isDeployBlockingFailure());
        self::assertTrue($overdue->isDeployBlockingFailure(strict: true));

        self::assertTrue($broken->isDeployBlockingFailure());
        self::assertFalse($broken->isAdvisoryFailure());

        self::assertFalse($passing->isDeployBlockingFailure(strict: true));
    }
}
