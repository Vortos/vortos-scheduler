<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Command\Handler;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Command\Handler\PruneSchedulerRunsHandler;
use Vortos\Scheduler\Command\PruneSchedulerRunsCommand;
use Vortos\Scheduler\Fire\RunState;
use Vortos\Scheduler\Fire\ScheduleRun;
use Vortos\Scheduler\Observability\SchedulerTracer;
use Vortos\Scheduler\Retention\RunRetentionSweeper;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Store\PruneResult;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;
use Vortos\Scheduler\Testing\InMemoryRunRetentionOverrideStore;

final class PruneSchedulerRunsHandlerTest extends TestCase
{
    public function test_invoke_delegates_to_sweeper_with_auto_trigger(): void
    {
        $runStore = new class implements ScheduleRunStoreInterface {
            public array $calls = [];

            public function insertRun(ScheduleRun $run): void {}
            public function findLastSlots(array $scheduleIds, ?string $tenantId): array { return []; }
            public function findRunState(ScheduleId $scheduleId, string $slot, ?string $tenantId): ?RunState { return null; }
            public function transitionRunState(string $runId, RunState $newState, DateTimeImmutable $at): void {}
            public function findRunBySlot(ScheduleId $scheduleId, string $slot, ?string $tenantId): ?ScheduleRun { return null; }
            public function findLastDispatchTimes(array $scheduleIds, ?string $tenantId): array { return []; }

            public function pruneOldRuns(DateTimeImmutable $before, ?string $tenantId = null, array $excludeTenantIds = []): PruneResult
            {
                $this->calls[] = [$before, $tenantId, $excludeTenantIds];

                return new PruneResult(0, false);
            }
        };

        $sweeper = new RunRetentionSweeper(
            runStore:            $runStore,
            overrideStore:       new InMemoryRunRetentionOverrideStore(),
            clock:                new MutableClock(new DateTimeImmutable('2026-07-01T03:00:00Z')),
            tracer:               new SchedulerTracer(null),
            globalRetentionDays: 30,
        );

        $handler = new PruneSchedulerRunsHandler($sweeper);
        $handler(new PruneSchedulerRunsCommand());

        self::assertCount(1, $runStore->calls);
    }
}
