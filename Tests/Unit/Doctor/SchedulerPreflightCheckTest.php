<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Doctor;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Plan\CurrentDeployState;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Deploy\Strategy\DeployStrategy;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\OpsKit\Gate\GateDisposition;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Schema\SchemaFingerprint;
use Vortos\Scheduler\Doctor\SchedulerDoctorFinding;
use Vortos\Scheduler\Doctor\SchedulerDoctorCheck;
use Vortos\Scheduler\Doctor\SchedulerDoctorPort;
use Vortos\Scheduler\Doctor\SchedulerDoctorReport;
use Vortos\Scheduler\Doctor\SchedulerDoctorStatus;
use Vortos\Scheduler\Doctor\SchedulerPreflightCheck;

/**
 * Unit tests for SchedulerPreflightCheck (D).
 *
 * Tests the deploy:doctor integration bridge. The check delegates to SchedulerDoctor::run()
 * and maps the result to a PreflightFinding. It must never mutate schedule data.
 */
final class SchedulerPreflightCheckTest extends TestCase
{
    private StubDoctorForPreflight $doctor;
    private SchedulerPreflightCheck $check;
    private PreflightContext $context;

    protected function setUp(): void
    {
        $this->doctor  = new StubDoctorForPreflight();
        $this->check   = new SchedulerPreflightCheck($this->doctor);
        $this->context = $this->makeContext();
    }

    private function makeContext(): PreflightContext
    {
        $definition = DeploymentDefinition::build(
            host:         'fake-host',
            registry:     'fake-registry',
            credential:   'fake-cred',
            strategy:     DeployStrategy::BlueGreen,
            arch:         Arch::Arm64,
            autoRollback: true,
        );

        $manifest = new BuildManifest(
            buildId:           'build-test',
            gitSha:            'abc1234',
            imageRepository: 'ghcr.io/acme/app',
            imageDigest:       'sha256:' . str_repeat('a', 64),
            targetArch:        Arch::Arm64,
            environment:       'testing',
            schemaFingerprint: new SchemaFingerprint([]),
            createdAt:         new DateTimeImmutable('2026-01-01'),
        );

        $state = new CurrentDeployState(
            activeColor:               ActiveColor::Blue,
            currentDigest:             'sha256:' . str_repeat('a', 64),
            appliedFingerprint:        new SchemaFingerprint([]),
            pendingContractMigrations: [],
        );

        return new PreflightContext($definition, $manifest, $state, new EnvironmentName('testing'));
    }

    public function test_id_returns_scheduler_doctor(): void
    {
        self::assertSame('scheduler.doctor', $this->check->id());
    }

    public function test_category_returns_capability(): void
    {
        self::assertSame(PreflightCategory::Capability, $this->check->category());
    }

    public function test_all_checks_pass_returns_pass_finding(): void
    {
        $this->doctor->report = new SchedulerDoctorReport([
            new SchedulerDoctorFinding(SchedulerDoctorCheck::C1, SchedulerDoctorStatus::Pass, 'Lease driver ok'),
            new SchedulerDoctorFinding(SchedulerDoctorCheck::C2, SchedulerDoctorStatus::Pass, 'Audit table ok'),
        ]);

        $finding = $this->check->check($this->context);

        self::assertFalse($finding->isFailure());
        self::assertStringContainsString('2', $finding->summary);
    }

    public function test_one_failing_check_returns_fail_finding(): void
    {
        $this->doctor->report = new SchedulerDoctorReport([
            new SchedulerDoctorFinding(SchedulerDoctorCheck::C1, SchedulerDoctorStatus::Pass, 'ok'),
            new SchedulerDoctorFinding(SchedulerDoctorCheck::C3, SchedulerDoctorStatus::Fail, 'Lease backend unreachable'),
        ]);

        $finding = $this->check->check($this->context);

        self::assertTrue($finding->isFailure());
        self::assertStringContainsString('C3', $finding->detail);
        self::assertStringContainsString('Lease backend unreachable', $finding->detail);
        self::assertStringContainsString('1', $finding->summary);
    }

    public function test_multiple_failing_checks_all_appear_in_detail(): void
    {
        $this->doctor->report = new SchedulerDoctorReport([
            new SchedulerDoctorFinding(SchedulerDoctorCheck::C1, SchedulerDoctorStatus::Fail, 'Store table missing'),
            new SchedulerDoctorFinding(SchedulerDoctorCheck::C3, SchedulerDoctorStatus::Fail, 'Lease backend down'),
            new SchedulerDoctorFinding(SchedulerDoctorCheck::C5, SchedulerDoctorStatus::Pass, 'ok'),
        ]);

        $finding = $this->check->check($this->context);

        self::assertTrue($finding->isFailure());
        self::assertStringContainsString('C1', $finding->detail);
        self::assertStringContainsString('C3', $finding->detail);
        self::assertStringNotContainsString('C5', $finding->detail);
    }

    public function test_exception_thrown_by_doctor_returns_fail_finding(): void
    {
        $this->doctor->exception = new \RuntimeException('Doctor blew up');

        $finding = $this->check->check($this->context);

        self::assertTrue($finding->isFailure());
        self::assertStringContainsString('exception', strtolower($finding->summary));
        self::assertStringContainsString('Doctor blew up', $finding->detail);
    }

    public function test_finding_contains_remediation_hint_on_failure(): void
    {
        $this->doctor->report = new SchedulerDoctorReport([
            new SchedulerDoctorFinding(SchedulerDoctorCheck::C9, SchedulerDoctorStatus::Fail, 'Dead schedule detected'),
        ]);

        $finding = $this->check->check($this->context);

        self::assertStringContainsString('scheduler:doctor', $finding->remediation);
    }

    public function test_skip_findings_are_not_counted_as_failures(): void
    {
        $this->doctor->report = new SchedulerDoctorReport([
            new SchedulerDoctorFinding(SchedulerDoctorCheck::C1, SchedulerDoctorStatus::Pass, 'ok'),
            new SchedulerDoctorFinding(SchedulerDoctorCheck::C7, SchedulerDoctorStatus::Skip, 'Approval store not installed'),
        ]);

        $finding = $this->check->check($this->context);

        self::assertFalse($finding->isFailure());
    }

    public function test_all_findings_pass_includes_count_in_summary(): void
    {
        $findings = array_map(
            fn($i) => new SchedulerDoctorFinding(SchedulerDoctorCheck::from("C{$i}"), SchedulerDoctorStatus::Pass, "ok-{$i}"),
            range(1, 9),
        );
        $this->doctor->report = new SchedulerDoctorReport($findings);

        $finding = $this->check->check($this->context);

        self::assertFalse($finding->isFailure());
        self::assertStringContainsString('9', $finding->summary);
    }

    public function test_a_runtime_state_failure_does_not_block_the_deploy(): void
    {
        // Regression: the release that fixed the interval-trigger bug was REFUSED by the deploy
        // gate, because C14 correctly reported the 11 schedules that same bug had stalled. A gate
        // that blocks the remedy on the symptom is a deadlock. C14 is Advisory by declaration
        // (SchedulerDoctorCheck::disposition()), so the bridge reports a failure that does not block.
        $this->doctor->report = new SchedulerDoctorReport([
            new SchedulerDoctorFinding(SchedulerDoctorCheck::C1, SchedulerDoctorStatus::Pass, 'fine'),
            new SchedulerDoctorFinding(
                SchedulerDoctorCheck::C14,
                SchedulerDoctorStatus::Fail,
                '11 of 15 active schedule(s) are overdue.',
                'payment-reminders has NEVER dispatched',
                'remediation',
            ),
        ]);

        $finding = $this->check->check($this->context);

        self::assertTrue($finding->isFailure(), 'the failure must stay visible in the deploy log');
        self::assertFalse($finding->blocksRelease(), 'runtime state must not gate the deploy');
        self::assertSame(GateDisposition::Advisory, $finding->disposition());
        self::assertStringContainsString('runtime-state warning', $finding->summary);
        self::assertStringContainsString('C14', $finding->detail);
    }

    public function test_strict_mode_turns_a_runtime_state_warning_into_a_refusal(): void
    {
        $this->doctor->report = new SchedulerDoctorReport([
            new SchedulerDoctorFinding(SchedulerDoctorCheck::C12, SchedulerDoctorStatus::Fail, '3 fire(s) dead-lettered'),
        ]);

        $finding = $this->check->check($this->context);

        self::assertFalse($finding->blocksRelease(strict: false));
        self::assertTrue($finding->blocksRelease(strict: true));
    }

    public function test_a_configuration_failure_still_blocks_the_deploy(): void
    {
        // The other half of the contract: configuration failures keep gating even when advisory
        // failures are present alongside them — the bridge reports the strictest thing it saw.
        $this->doctor->report = new SchedulerDoctorReport([
            new SchedulerDoctorFinding(SchedulerDoctorCheck::C3, SchedulerDoctorStatus::Fail, 'command not allowlisted'),
            new SchedulerDoctorFinding(SchedulerDoctorCheck::C14, SchedulerDoctorStatus::Fail, 'overdue'),
        ]);

        $finding = $this->check->check($this->context);

        self::assertTrue($finding->blocksRelease());
        self::assertStringContainsString('C3', $finding->detail);
        self::assertStringNotContainsString('C14', $finding->detail, 'only the blocking failures explain a refusal');
    }
}

// ── Test doubles ──────────────────────────────────────────────────────────────

final class StubDoctorForPreflight implements SchedulerDoctorPort
{
    public ?SchedulerDoctorReport $report    = null;
    public ?\Throwable             $exception = null;

    public function run(): SchedulerDoctorReport
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->report ?? new SchedulerDoctorReport([]);
    }
}
