<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Console;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Scheduler\Console\SchedulePruneCommand;
use Vortos\Scheduler\Fire\RunState;
use Vortos\Scheduler\Fire\ScheduleRun;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Store\Exception\DuplicateSlotException;
use Vortos\Scheduler\Store\Exception\InvalidRunStateTransitionException;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;

/**
 * @covers \Vortos\Scheduler\Console\SchedulePruneCommand
 */
final class SchedulePruneCommandTest extends TestCase
{
    private function makeRunStore(int $deleted = 5): ScheduleRunStoreInterface
    {
        return new class($deleted) implements ScheduleRunStoreInterface {
            public ?DateTimeImmutable $prunedBefore = null;
            public function __construct(private readonly int $deleted) {}

            public function insertRun(ScheduleRun $run): void {}
            public function findLastSlots(array $scheduleIds, ?string $tenantId): array { return []; }
            public function findRunState(ScheduleId $scheduleId, string $slot, ?string $tenantId): ?RunState { return null; }
            public function transitionRunState(string $runId, RunState $newState, DateTimeImmutable $at): void {}
            public function findRunBySlot(ScheduleId $scheduleId, string $slot, ?string $tenantId): ?ScheduleRun { return null; }
            public function findLastDispatchTimes(array $scheduleIds, ?string $tenantId): array { return []; }

            public function pruneOldRuns(DateTimeImmutable $before): int
            {
                $this->prunedBefore = $before;
                return $this->deleted;
            }
        };
    }

    public function test_prune_outputs_deleted_count(): void
    {
        $command = new SchedulePruneCommand($this->makeRunStore(7));
        $tester  = new CommandTester($command);
        $tester->execute([]);
        self::assertStringContainsString('7', $tester->getDisplay());
        self::assertSame(0, $tester->getStatusCode());
    }

    public function test_prune_default_cutoff_is_30_days(): void
    {
        $store   = $this->makeRunStore(0);
        $command = new SchedulePruneCommand($store);
        $tester  = new CommandTester($command);
        $tester->execute([]);

        $cutoff = $store->prunedBefore;
        self::assertNotNull($cutoff);
        $diff = (new DateTimeImmutable())->diff($cutoff);
        self::assertGreaterThan(25, $diff->days);
    }

    public function test_prune_before_option_used_as_cutoff(): void
    {
        $store   = $this->makeRunStore(0);
        $command = new SchedulePruneCommand($store);
        $tester  = new CommandTester($command);
        $tester->execute(['--before' => '2026-01-01T00:00:00Z']);

        self::assertNotNull($store->prunedBefore);
        self::assertSame('2026-01-01', $store->prunedBefore->format('Y-m-d'));
    }

    public function test_prune_invalid_before_exits_failure(): void
    {
        $command = new SchedulePruneCommand($this->makeRunStore());
        $tester  = new CommandTester($command);
        $tester->execute(['--before' => 'not-a-date']);
        self::assertSame(1, $tester->getStatusCode());
    }

    public function test_dry_run_does_not_call_prune(): void
    {
        $store   = $this->makeRunStore(0);
        $command = new SchedulePruneCommand($store);
        $tester  = new CommandTester($command);
        $tester->execute(['--dry-run' => true]);

        self::assertNull($store->prunedBefore);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('ry-run', $tester->getDisplay());
    }

    public function test_dry_run_with_json_outputs_json(): void
    {
        $command = new SchedulePruneCommand($this->makeRunStore(0));
        $tester  = new CommandTester($command);
        $tester->execute(['--dry-run' => true, '--json' => true]);

        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['dry_run']);
        self::assertSame(0, $tester->getStatusCode());
    }

    public function test_json_output_includes_deleted_count(): void
    {
        $command = new SchedulePruneCommand($this->makeRunStore(12));
        $tester  = new CommandTester($command);
        $tester->execute(['--json' => true]);

        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(12, $data['deleted']);
    }

    public function test_prune_zero_rows_exits_zero(): void
    {
        $command = new SchedulePruneCommand($this->makeRunStore(0));
        $tester  = new CommandTester($command);
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());
    }
}
