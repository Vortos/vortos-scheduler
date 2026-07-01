<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Console;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Console\SchedulePruneCommand;
use Vortos\Scheduler\Fire\RunState;
use Vortos\Scheduler\Fire\ScheduleRun;
use Vortos\Scheduler\Observability\SchedulerTracer;
use Vortos\Scheduler\Retention\RunRetentionSweeper;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Store\PruneResult;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;
use Vortos\Scheduler\Testing\InMemoryRunRetentionOverrideStore;

/**
 * @covers \Vortos\Scheduler\Console\SchedulePruneCommand
 */
final class SchedulePruneCommandTest extends TestCase
{
    private FakePruneRunStore $runStore;

    protected function setUp(): void
    {
        $this->runStore = new FakePruneRunStore();
    }

    // ── --before bypass mode ─────────────────────────────────────────────────

    public function test_bypass_before_option_prunes_at_explicit_cutoff(): void
    {
        $this->runStore->nextResult = new PruneResult(7, false);
        $command = new SchedulePruneCommand($this->runStore);
        $tester  = new CommandTester($command);

        $tester->execute(['--before' => '2026-01-01T00:00:00Z']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('7', $tester->getDisplay());
        self::assertNotNull($this->runStore->lastCall);
        self::assertSame('2026-01-01', $this->runStore->lastCall['before']->format('Y-m-d'));
    }

    public function test_bypass_with_tenant_scopes_the_call(): void
    {
        $command = new SchedulePruneCommand($this->runStore);
        $tester  = new CommandTester($command);

        $tester->execute(['--before' => '2026-01-01T00:00:00Z', '--tenant' => 'tenant-a']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('tenant-a', $this->runStore->lastCall['tenantId']);
    }

    public function test_tenant_without_before_exits_failure(): void
    {
        $command = new SchedulePruneCommand($this->runStore);
        $tester  = new CommandTester($command);

        $tester->execute(['--tenant' => 'tenant-a']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertNull($this->runStore->lastCall);
    }

    public function test_bypass_invalid_before_exits_failure(): void
    {
        $command = new SchedulePruneCommand($this->runStore);
        $tester  = new CommandTester($command);

        $tester->execute(['--before' => 'not-a-date']);

        self::assertSame(1, $tester->getStatusCode());
    }

    public function test_bypass_dry_run_does_not_call_store(): void
    {
        $command = new SchedulePruneCommand($this->runStore);
        $tester  = new CommandTester($command);

        $tester->execute(['--before' => '2026-01-01T00:00:00Z', '--dry-run' => true]);

        self::assertNull($this->runStore->lastCall);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('ry-run', $tester->getDisplay());
    }

    public function test_bypass_json_output_includes_deleted_and_mode(): void
    {
        $this->runStore->nextResult = new PruneResult(12, false);
        $command = new SchedulePruneCommand($this->runStore);
        $tester  = new CommandTester($command);

        $tester->execute(['--before' => '2026-01-01T00:00:00Z', '--json' => true]);

        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(12, $data['deleted']);
        self::assertSame('bypass', $data['mode']);
    }

    public function test_bypass_truncated_is_reported(): void
    {
        $this->runStore->nextResult = new PruneResult(5000, true);
        $command = new SchedulePruneCommand($this->runStore);
        $tester  = new CommandTester($command);

        $tester->execute(['--before' => '2026-01-01T00:00:00Z']);

        self::assertStringContainsString('more may remain', $tester->getDisplay());
    }

    // ── default policy-aware mode ────────────────────────────────────────────

    public function test_default_mode_delegates_to_sweeper(): void
    {
        $this->runStore->nextResult = new PruneResult(3, false);
        $sweeper = $this->makeSweeper();
        $command = new SchedulePruneCommand($this->runStore, $sweeper);
        $tester  = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('3', $tester->getDisplay());
        // The sweeper calls pruneOldRuns() itself — confirms it was actually invoked.
        self::assertNotNull($this->runStore->lastCall);
    }

    public function test_default_mode_dry_run_does_not_call_sweeper(): void
    {
        $sweeper = $this->makeSweeper();
        $command = new SchedulePruneCommand($this->runStore, $sweeper);
        $tester  = new CommandTester($command);

        $tester->execute(['--dry-run' => true]);

        self::assertNull($this->runStore->lastCall);
        self::assertSame(0, $tester->getStatusCode());
    }

    public function test_default_mode_json_output_mode_is_policy(): void
    {
        $sweeper = $this->makeSweeper();
        $command = new SchedulePruneCommand($this->runStore, $sweeper);
        $tester  = new CommandTester($command);

        $tester->execute(['--json' => true]);

        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('policy', $data['mode']);
    }

    public function test_default_mode_without_sweeper_exits_failure(): void
    {
        $command = new SchedulePruneCommand($this->runStore); // no sweeper wired
        $tester  = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('unavailable', $tester->getDisplay());
    }

    private function makeSweeper(): RunRetentionSweeper
    {
        return new RunRetentionSweeper(
            runStore:            $this->runStore,
            overrideStore:       new InMemoryRunRetentionOverrideStore(),
            clock:                new MutableClock(new DateTimeImmutable('2026-07-01T03:00:00Z')),
            tracer:               new SchedulerTracer(null),
            globalRetentionDays: 30,
        );
    }
}

final class FakePruneRunStore implements ScheduleRunStoreInterface
{
    public ?array $lastCall = null;
    public PruneResult $nextResult;

    public function __construct()
    {
        $this->nextResult = new PruneResult(0, false);
    }

    public function insertRun(ScheduleRun $run): void {}
    public function findLastSlots(array $scheduleIds, ?string $tenantId): array { return []; }
    public function findRunState(ScheduleId $scheduleId, string $slot, ?string $tenantId): ?RunState { return null; }
    public function transitionRunState(string $runId, RunState $newState, DateTimeImmutable $at): void {}
    public function findRunBySlot(ScheduleId $scheduleId, string $slot, ?string $tenantId): ?ScheduleRun { return null; }
    public function findLastDispatchTimes(array $scheduleIds, ?string $tenantId): array { return []; }

    public function pruneOldRuns(DateTimeImmutable $before, ?string $tenantId = null, array $excludeTenantIds = []): PruneResult
    {
        $this->lastCall = ['before' => $before, 'tenantId' => $tenantId, 'excludeTenantIds' => $excludeTenantIds];

        return $this->nextResult;
    }
}
