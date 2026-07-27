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

        // Only DEPLOY-BLOCKING failures gate. Runtime-state checks (C14, overdue schedules) stay
        // visible in `scheduler:doctor` and in alerting, but must not veto a release — a stalled
        // schedule is usually fixed BY deploying, so gating on it deadlocks the fix behind the
        // symptom. That is not hypothetical: the release that fixed the interval-trigger bug was
        // refused by the check reporting the 11 schedules that bug had stalled.
        $blocking = array_filter(
            $report->findings,
            fn (SchedulerDoctorFinding $f) => $f->isDeployBlockingFailure(),
        );

        if ($blocking !== []) {
            $failMessages = array_map(
                fn (SchedulerDoctorFinding $f) => "[{$f->checkId}] {$f->summary}",
                $blocking,
            );

            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                sprintf('%d scheduler doctor check(s) failed.', count($failMessages)),
                detail: implode('; ', $failMessages),
                remediation: 'Run `php bin/console scheduler:doctor` for per-check details and fix instructions.',
            );
        }

        // Non-gating failures are still surfaced, so a passing gate never hides them from the
        // deploy log — the operator sees them, the pipeline just does not stop for them.
        $advisory = array_map(
            fn (SchedulerDoctorFinding $f) => "[{$f->checkId}] {$f->summary}",
            array_filter($report->findings, fn (SchedulerDoctorFinding $f) => $f->isFailure()),
        );

        if ($advisory !== []) {
            return PreflightFinding::pass(
                $this->id(),
                $this->category(),
                sprintf(
                    '%d scheduler doctor check(s) passed the deploy gate; %d runtime-state warning(s).',
                    count($report->findings) - count($advisory),
                    count($advisory),
                ),
                detail: 'Not blocking, but investigate: ' . implode('; ', $advisory),
            );
        }

        return PreflightFinding::pass(
            $this->id(),
            $this->category(),
            sprintf('All %d scheduler doctor checks passed.', count($report->findings)),
        );
    }
}
