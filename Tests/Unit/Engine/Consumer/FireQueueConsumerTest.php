<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Engine\Consumer;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Vortos\Cqrs\Command\CommandBusInterface;
use Vortos\Domain\Command\CommandInterface;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Engine\Consumer\FireQueueConsumer;
use Vortos\Scheduler\Fire\CommandHydrator;
use Vortos\Scheduler\Fire\RunState;
use Vortos\Scheduler\Fire\ScheduleRun;
use Vortos\Scheduler\Observability\SchedulerTracer;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Store\PruneResult;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;

final class FireQueueConsumerTest extends TestCase
{
    private const TABLE = 'vortos_scheduler_fire_queue';

    private Connection        $connection;
    private RecordingConsumeRunStore $runStore;
    private FakeConsumeCommandBus    $commandBus;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('
            CREATE TABLE ' . self::TABLE . ' (
                id TEXT NOT NULL PRIMARY KEY,
                run_id TEXT NOT NULL,
                schedule_id TEXT NOT NULL,
                tenant_id TEXT NULL,
                slot TEXT NOT NULL,
                scheduled_for DATETIME NOT NULL,
                command_class TEXT NOT NULL,
                command_payload TEXT NOT NULL,
                metadata TEXT NULL,
                status TEXT NOT NULL DEFAULT "pending",
                created_at DATETIME NOT NULL,
                dispatched_at DATETIME NULL,
                failure_reason TEXT NULL
            )
        ');

        $this->runStore   = new RecordingConsumeRunStore();
        $this->commandBus = new FakeConsumeCommandBus();
    }

    public function test_empty_queue_processes_nothing(): void
    {
        self::assertSame(0, $this->makeConsumer()->consumeBatch(10));
    }

    public function test_successful_row_dispatches_and_marks_completed(): void
    {
        $this->insertRow('run-1', FixtureConsumeCommand::class);

        $processed = $this->makeConsumer()->consumeBatch(10);

        self::assertSame(1, $processed);
        self::assertCount(1, $this->commandBus->dispatched);
        self::assertSame(['run-1' => RunState::Completed], $this->runStore->transitions);

        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . " WHERE run_id = 'run-1'");
        self::assertSame('dispatched', $row['status']);
        self::assertNull($row['failure_reason']);
    }

    public function test_handler_exception_marks_row_failed_and_transitions_ledger_to_failed(): void
    {
        $this->insertRow('run-2', FixtureConsumeCommand::class);
        $this->commandBus->throwFor(FixtureConsumeCommand::class, new \RuntimeException('handler exploded'));

        $processed = $this->makeConsumer()->consumeBatch(10);

        self::assertSame(1, $processed);
        self::assertSame(['run-2' => RunState::Failed], $this->runStore->transitions);

        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . " WHERE run_id = 'run-2'");
        self::assertSame('failed', $row['status']);
        self::assertStringContainsString('handler exploded', (string) $row['failure_reason']);
    }

    public function test_one_failing_row_does_not_block_the_rest_of_the_batch(): void
    {
        $this->insertRow('run-fail', FixtureConsumeCommand::class);
        $this->insertRow('run-ok', FixtureConsumeCommand::class);
        $this->commandBus->throwFor(FixtureConsumeCommand::class, new \RuntimeException('boom'), onlyForRunOrder: 1);

        $processed = $this->makeConsumer()->consumeBatch(10);

        self::assertSame(2, $processed);
        self::assertCount(2, $this->commandBus->dispatched);
    }

    public function test_claimed_rows_leave_processing_state(): void
    {
        $this->insertRow('run-3', FixtureConsumeCommand::class);

        $this->makeConsumer()->consumeBatch(10);

        $status = $this->connection->fetchOne('SELECT status FROM ' . self::TABLE . " WHERE run_id = 'run-3'");
        self::assertNotSame('processing', $status);
        self::assertNotSame('pending', $status);
    }

    public function test_batch_size_limits_rows_claimed(): void
    {
        $this->insertRow('run-a', FixtureConsumeCommand::class);
        $this->insertRow('run-b', FixtureConsumeCommand::class);
        $this->insertRow('run-c', FixtureConsumeCommand::class);

        $processed = $this->makeConsumer()->consumeBatch(2);

        self::assertSame(2, $processed);

        $pending = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . " WHERE status = 'pending'");
        self::assertSame(1, $pending);
    }

    private function insertRow(string $runId, string $commandClass, ?string $payload = null): void
    {
        $this->connection->insert(self::TABLE, [
            'id'              => 'row-' . $runId,
            'run_id'          => $runId,
            'schedule_id'     => ScheduleId::generate()->toString(),
            'tenant_id'       => null,
            'slot'            => 'slot-' . $runId,
            'scheduled_for'   => '2026-07-01 10:00:00',
            'command_class'   => $commandClass,
            'command_payload' => $payload ?? '[]',
            'metadata'        => json_encode(['X-Scheduler-Run-Id' => $runId], JSON_THROW_ON_ERROR),
            'status'          => 'pending',
            'created_at'      => '2026-07-01 10:00:00',
        ]);
    }

    private function makeConsumer(): FireQueueConsumer
    {
        return new FireQueueConsumer(
            connection: $this->connection,
            runStore:   $this->runStore,
            commandBus: $this->commandBus,
            hydrator:   new CommandHydrator(),
            clock:      new MutableClock(new DateTimeImmutable('2026-07-01T10:05:00Z')),
            tracer:     new SchedulerTracer(null),
            logger:     new NullLogger(),
            table:      self::TABLE,
        );
    }
}

final readonly class FixtureConsumeCommand implements CommandInterface
{
    public function idempotencyKey(): ?string
    {
        return null;
    }
}

final class FakeConsumeCommandBus implements CommandBusInterface
{
    /** @var list<CommandInterface> */
    public array $dispatched = [];

    /** @var array<class-string, \Throwable> */
    private array $throwFor = [];
    private ?int  $onlyForRunOrder = null;
    private int   $callCount = 0;

    public function throwFor(string $commandClass, \Throwable $e, ?int $onlyForRunOrder = null): void
    {
        $this->throwFor[$commandClass] = $e;
        $this->onlyForRunOrder = $onlyForRunOrder;
    }

    public function dispatch(CommandInterface $command): mixed
    {
        $this->dispatched[] = $command;
        $this->callCount++;

        $class = get_class($command);

        if (isset($this->throwFor[$class])) {
            if ($this->onlyForRunOrder === null || $this->onlyForRunOrder === $this->callCount) {
                throw $this->throwFor[$class];
            }
        }

        return null;
    }
}

final class RecordingConsumeRunStore implements ScheduleRunStoreInterface
{
    /** @var array<string, RunState> */
    public array $transitions = [];

    public function insertRun(ScheduleRun $run): void {}
    public function findLastSlots(array $scheduleIds, ?string $tenantId): array { return []; }
    public function findRunState(ScheduleId $scheduleId, string $slot, ?string $tenantId): ?RunState { return null; }

    public function transitionRunState(string $runId, RunState $newState, DateTimeImmutable $at): void
    {
        $this->transitions[$runId] = $newState;
    }

    public function findRunBySlot(ScheduleId $scheduleId, string $slot, ?string $tenantId): ?ScheduleRun { return null; }
    public function findLastDispatchTimes(array $scheduleIds, ?string $tenantId): array { return []; }

    public function pruneOldRuns(DateTimeImmutable $before, ?string $tenantId = null, array $excludeTenantIds = []): PruneResult
    {
        return new PruneResult(0, false);
    }
}
