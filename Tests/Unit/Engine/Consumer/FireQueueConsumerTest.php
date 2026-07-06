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
use Vortos\Scheduler\Engine\Consumer\ConsumerCapabilityResolverInterface;
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
                failure_reason TEXT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                available_at DATETIME NULL,
                last_error TEXT NULL
            )
        ');

        $this->runStore   = new RecordingConsumeRunStore();
        $this->commandBus = new FakeConsumeCommandBus();
    }

    public function test_empty_queue_processes_nothing(): void
    {
        self::assertSame(0, $this->makeConsumer()->consumeBatch(10));
    }

    public function test_null_command_bus_throws_before_claiming_any_row(): void
    {
        $this->insertRow('run-1', FixtureConsumeCommand::class);

        $consumer = new FireQueueConsumer(
            connection: $this->connection,
            runStore:   $this->runStore,
            commandBus: null,
            hydrator:   new CommandHydrator(),
            clock:      new MutableClock(new DateTimeImmutable('2026-07-01T10:05:00Z')),
            tracer:     new SchedulerTracer(null),
            logger:     new NullLogger(),
            table:      self::TABLE,
        );

        try {
            $consumer->consumeBatch(10);
            self::fail('Expected a RuntimeException when no CommandBus is wired.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('no CQRS CommandBus is wired', $e->getMessage());
        }

        // The pending row must be untouched — the guard fires before any claim, so nothing is
        // stranded in 'processing'.
        $status = $this->connection->fetchOne(
            'SELECT status FROM ' . self::TABLE . " WHERE run_id = 'run-1'",
        );
        self::assertSame('pending', $status);
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

    public function test_capability_filter_leaves_incapable_fires_for_a_capable_consumer(): void
    {
        // R7-4 / SCHED-1: node capable of only FixtureConsumeCommand must claim its fire and leave
        // the RunDatabaseBackup fire untouched for a node that has that class.
        $this->insertRow('run-known', FixtureConsumeCommand::class);
        $this->insertRow('run-foreign', 'App\\Shared\\RunDatabaseBackup');

        $resolver = new FakeCapabilityResolver([FixtureConsumeCommand::class]);
        $processed = $this->makeConsumer($resolver)->consumeBatch(10);

        self::assertSame(1, $processed, 'Only the capable fire should be claimed.');
        self::assertSame('dispatched', $this->statusOf('run-known'));
        self::assertSame('pending', $this->statusOf('run-foreign'), 'The foreign fire must be left pending.');
        self::assertSame(0, (int) $this->columnOf('run-foreign', 'attempts'), 'Unclaimed fire is not counted as an attempt.');
    }

    public function test_unknown_class_is_requeued_not_failed(): void
    {
        // No capability filter (null resolver) so the row IS claimed, then the requeue net kicks in
        // because the class does not exist.
        $this->insertRow('run-x', 'App\\Does\\Not\\Exist');

        $processed = $this->makeConsumer()->consumeBatch(10);

        self::assertSame(1, $processed);
        self::assertSame('pending', $this->statusOf('run-x'), 'Unknown class must requeue, not fail.');
        self::assertSame(1, (int) $this->columnOf('run-x', 'attempts'));
        self::assertNotNull($this->columnOf('run-x', 'available_at'));
        self::assertArrayNotHasKey('run-x', $this->runStore->transitions, 'Requeue must not fail the run ledger.');
    }

    public function test_requeued_row_is_invisible_until_available_at(): void
    {
        $this->insertRow('run-x', 'App\\Does\\Not\\Exist');

        $this->makeConsumer()->consumeBatch(10); // first attempt → requeued with future available_at

        // A second consume at the SAME clock time must not re-claim the backed-off row.
        $processed = $this->makeConsumer()->consumeBatch(10);
        self::assertSame(0, $processed, 'Backed-off row must be invisible until available_at passes.');
    }

    public function test_dead_letters_after_max_attempts(): void
    {
        $this->insertRow('run-x', 'App\\Does\\Not\\Exist');
        // Pre-age the row to the last attempt so one more requeue crosses the cap.
        $this->connection->update(self::TABLE, ['attempts' => 2], ['run_id' => 'run-x']);

        // maxAttempts=3 → attempts becomes 3 which is >= 3 → dead_letter.
        $consumer = $this->makeConsumer(null, maxAttempts: 3);
        $consumer->consumeBatch(10);

        self::assertSame('dead_letter', $this->statusOf('run-x'));
        self::assertSame(['run-x' => RunState::Failed], $this->runStore->transitions);
    }

    public function test_genuine_command_failure_stays_terminal(): void
    {
        // Class exists and is capable, but the handler throws → terminal failed, not requeued.
        $this->insertRow('run-boom', FixtureConsumeCommand::class);
        $this->commandBus->throwFor(FixtureConsumeCommand::class, new \RuntimeException('handler exploded'));

        $this->makeConsumer()->consumeBatch(10);

        self::assertSame('failed', $this->statusOf('run-boom'));
        self::assertSame(['run-boom' => RunState::Failed], $this->runStore->transitions);
        self::assertSame(0, (int) $this->columnOf('run-boom', 'attempts'), 'A poison pill is not requeued.');
    }

    public function test_empty_capability_set_claims_nothing(): void
    {
        $this->insertRow('run-known', FixtureConsumeCommand::class);

        $resolver = new FakeCapabilityResolver([]); // capable of nothing
        $processed = $this->makeConsumer($resolver)->consumeBatch(10);

        self::assertSame(0, $processed);
        self::assertSame('pending', $this->statusOf('run-known'));
    }

    private function statusOf(string $runId): string
    {
        return (string) $this->connection->fetchOne(
            'SELECT status FROM ' . self::TABLE . ' WHERE run_id = ?',
            [$runId],
        );
    }

    private function columnOf(string $runId, string $column): mixed
    {
        return $this->connection->fetchOne(
            "SELECT {$column} FROM " . self::TABLE . ' WHERE run_id = ?',
            [$runId],
        );
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

    private function makeConsumer(
        ?ConsumerCapabilityResolverInterface $capabilityResolver = null,
        int $maxAttempts = 10,
    ): FireQueueConsumer {
        return new FireQueueConsumer(
            connection: $this->connection,
            runStore:   $this->runStore,
            commandBus: $this->commandBus,
            hydrator:   new CommandHydrator(),
            clock:      new MutableClock(new DateTimeImmutable('2026-07-01T10:05:00Z')),
            tracer:     new SchedulerTracer(null),
            logger:     new NullLogger(),
            table:      self::TABLE,
            capabilityResolver: $capabilityResolver,
            maxAttempts: $maxAttempts,
        );
    }
}

final class FakeCapabilityResolver implements ConsumerCapabilityResolverInterface
{
    /** @param list<string>|null $capable */
    public function __construct(private readonly ?array $capable) {}

    public function capableCommandClasses(): ?array
    {
        return $this->capable;
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
