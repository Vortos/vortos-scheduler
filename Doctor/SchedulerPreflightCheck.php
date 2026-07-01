<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Doctor;

use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;

/**
 * Wires `scheduler:doctor` (9 checks, fail-closed) into the `deploy:doctor` gate.
 *
 * Only registered when `vortos-deploy` is installed — the {@see SchedulerExtension}
 * guards registration with `interface_exists(PreflightCheckInterface::class)`.
 *
 * This check is read-only (C4 and C9 do acquire+release a tiny probe lease; C5 does
 * SELECT 1 FROM table). It never mutates schedule data or audit records.
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

        if (!$report->isClear()) {
            $failMessages = array_map(
                fn (SchedulerDoctorFinding $f) => "[{$f->checkId}] {$f->summary}",
                array_filter($report->findings, fn (SchedulerDoctorFinding $f) => $f->isFailure()),
            );

            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                sprintf('%d scheduler doctor check(s) failed.', count($failMessages)),
                detail: implode('; ', $failMessages),
                remediation: 'Run `php bin/console scheduler:doctor` for per-check details and fix instructions.',
            );
        }

        return PreflightFinding::pass(
            $this->id(),
            $this->category(),
            sprintf('All %d scheduler doctor checks passed.', count($report->findings)),
        );
    }
}
