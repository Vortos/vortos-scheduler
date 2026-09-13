<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Doctor;

use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\OpsKit\Gate\GateDisposition;

/**
 * Bridges `scheduler:doctor` into `deploy:doctor`.
 *
 * The scheduler doctor mixes configuration checks with live runtime-state checks, and each declares
 * its own disposition ({@see SchedulerDoctorCheck::disposition()}). This bridge is one preflight
 * finding, so it reports the strictest thing it saw: a blocking scheduler failure is a blocking
 * finding; advisory-only failures are a failure narrowed to Advisory — shown as a warning on every
 * deploy, and blocking under `--strict`, but never a veto on the release that may be their cure.
 */
final class SchedulerPreflightCheck implements PreflightCheckInterface
{
    public function __construct(private readonly SchedulerDoctorPort $doctor) {}

    public function id(): string
    {
        return 'scheduler.doctor';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Capability;
    }

    public function disposition(): GateDisposition
    {
        return GateDisposition::Blocking;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        try {
            $report = $this->doctor->run();
        } catch (\Throwable $e) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'scheduler:doctor threw an exception during preflight.',
                detail: $e->getMessage(),
                remediation: 'Investigate the scheduler configuration and run scheduler:doctor manually.',
            );
        }

        $describe = static fn (SchedulerDoctorFinding $f): string => "[{$f->checkId}] {$f->summary}";

        $blocking = array_values(array_filter(
            $report->findings,
            static fn (SchedulerDoctorFinding $f): bool => $f->isDeployBlockingFailure(),
        ));

        if ($blocking !== []) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                sprintf('%d scheduler doctor check(s) failed.', count($blocking)),
                detail: implode('; ', array_map($describe, $blocking)),
                remediation: 'Run `php bin/console scheduler:doctor` for per-check details and fix instructions.',
            );
        }

        $advisory = array_values(array_filter(
            $report->findings,
            static fn (SchedulerDoctorFinding $f): bool => $f->isAdvisoryFailure(),
        ));

        if ($advisory !== []) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                sprintf('%d runtime-state warning(s) from scheduler:doctor.', count($advisory)),
                detail: implode('; ', array_map($describe, $advisory)),
                remediation: 'These describe the running scheduler, not this release. Investigate with '
                    . '`php bin/console scheduler:doctor`; the matching alerts fire independently of deploys.',
            )->withDisposition(GateDisposition::Advisory);
        }

        return PreflightFinding::pass(
            $this->id(),
            $this->category(),
            sprintf('All %d scheduler doctor checks passed.', count($report->findings)),
        );
    }
}
