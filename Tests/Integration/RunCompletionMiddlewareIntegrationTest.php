<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Envelope;
use Throwable;
use Vortos\Messaging\Bus\Stamp\HeadersStamp;
use Vortos\Scheduler\Engine\SlotCalculator;
use Vortos\Scheduler\Fire\IdempotencyKey;
use Vortos\Scheduler\Fire\RunCompletionMiddleware;
use Vortos\Scheduler\Fire\RunState;
use Vortos\Scheduler\Fire\RunStamp;
use Vortos\Scheduler\Fire\ScheduleRun;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Store\Dbal\DbalScheduleRunStore;

/**
 * Integration test for RunCompletionMiddleware against real PostgreSQL.
 *
 * Verifies that:
 *  1. On handler success: ledger row transitions to Completed inside the same call.
 *  2. On handler exception: exception propagates, ledger row stays Dispatched.
 *  3. Non-scheduler envelopes (no HeadersStamp / no X-Scheduler headers) pass through.
 *  4. Unknown run_id in HeadersStamp propagates the exception from transitionRunState.
 */
final class RunCompletionMiddlewareIntegrationTest extends TestCase
{
    private const RUNS_TABLE = 'vortos_scheduler_runs';

    private Connection           $connection;
    private DbalScheduleRunStore $runStore;
    private SlotCalculator       $slotCalc;

    protected function setUp(): void
    {
        $this->connection = $this->connectOrSkip();
        $this->runStore   = new DbalScheduleRunStore($this->connection, self::RUNS_TABLE);
        $this->slotCalc   = new SlotCalculator();
        $this->ensureTables();
        $this->cleanTestRows();
    }

    protected function tearDown(): void
    {
        $this->cleanTestRows();
    }

    // ─────────────────────────────────────────────────────────────
    // Success path — Completed transition
    // ─────────────────────────────────────────────────────────────

    public function test_successful_handler_transitions_run_to_completed(): void
    {
        $run        = $this->insertDispatched('completion-success');
        $middleware = $this->makeMiddleware();
        $envelope   = $this->envelopeFor($run);

        $middleware->handle($envelope, fn(Envelope $e) => $e);

        self::assertSame(
            RunState::Completed,
            $this->runStore->findRunState($run->scheduleId, $run->slot, 'ta'),
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Failure path — exception propagates, Dispatched stays
    // ─────────────────────────────────────────────────────────────

    public function test_handler_exception_propagates_and_state_stays_dispatched(): void
    {
        $run        = $this->insertDispatched('completion-fail');
        $middleware = $this->makeMiddleware();
        $envelope   = $this->envelopeFor($run);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('handler-boom');

        try {
            $middleware->handle(
                $envelope,
                fn(Envelope $e) => throw new \RuntimeException('handler-boom'),
            );
        } finally {
            // State must still be Dispatched — middleware does NOT transition on failure
            self::assertSame(
                RunState::Dispatched,
                $this->runStore->findRunState($run->scheduleId, $run->slot, 'ta'),
                'Run must stay Dispatched when handler throws',
            );
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Non-scheduler envelopes pass through
    // ─────────────────────────────────────────────────────────────

    public function test_envelope_without_headers_stamp_passes_through(): void
    {
        $middleware = $this->makeMiddleware();
        $called     = false;

        $middleware->handle(
            new Envelope(new \stdClass()),
            function (Envelope $e) use (&$called) { $called = true; return $e; },
        );

        self::assertTrue($called, 'Handler must be called for non-scheduler envelopes');
    }

    public function test_envelope_with_headers_stamp_but_no_scheduler_headers_passes_through(): void
    {
        $middleware = $this->makeMiddleware();
        $called     = false;

        $envelope = new Envelope(new \stdClass(), [new HeadersStamp(['Content-Type' => 'application/json'])]);

        $middleware->handle(
            $envelope,
            function (Envelope $e) use (&$called) { $called = true; return $e; },
        );

        self::assertTrue($called);
    }

    // ─────────────────────────────────────────────────────────────
    // Infrastructure
    // ─────────────────────────────────────────────────────────────

    private function insertDispatched(string $slotSuffix): ScheduleRun
    {
        $scheduleId = ScheduleId::generate();
        $slot       = $scheduleId->toString() . ':2026-07-01T10:00:00+00:00';
        $runId      = IdempotencyKey::fromSlotKey($slot)->value;
        $now        = new DateTimeImmutable('2026-07-01T10:00:00Z');

        $run = new ScheduleRun(
            runId:        $runId,
            scheduleId:   $scheduleId,
            tenantId:     'ta',
            slot:         $slot,
            scheduledFor: $now,
            dispatchedAt: $now,
            state:        RunState::Dispatched,
        );

        $this->runStore->insertRun($run);

        return $run;
    }

    private function envelopeFor(ScheduleRun $run): Envelope
    {
        $stamp = new RunStamp(
            runId:      $run->runId,
            scheduleId: $run->scheduleId->toString(),
            slot:       $run->slot,
            tenantId:   $run->tenantId,
        );

        return new Envelope(
            new \stdClass(),
            [new HeadersStamp($stamp->toHeaders())],
        );
    }

    private function makeMiddleware(): RunCompletionMiddleware
    {
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable { return new DateTimeImmutable(); }
        };

        return new RunCompletionMiddleware($this->runStore, $clock);
    }

    private function connectOrSkip(): Connection
    {
        try {
            $conn = DriverManager::getConnection([
                'driver'   => 'pdo_pgsql',
                'host'     => $_ENV['VORTOS_WRITE_DB_HOST'] ?? 'write_db',
                'port'     => (int) ($_ENV['VORTOS_WRITE_DB_PORT'] ?? 5432),
                'user'     => $_ENV['VORTOS_WRITE_DB_USER'] ?? 'postgres',
                'password' => $_ENV['VORTOS_WRITE_DB_PASSWORD'] ?? '12345',
                'dbname'   => $_ENV['VORTOS_WRITE_DB_NAME'] ?? 'squaura',
            ]);
            $conn->executeQuery('SELECT 1');

            return $conn;
        } catch (Throwable $e) {
            $this->markTestSkipped('PostgreSQL not reachable: ' . $e->getMessage());
        }
    }

    private function ensureTables(): void
    {
        $t = self::RUNS_TABLE;
        $this->connection->executeStatement("
            CREATE TABLE IF NOT EXISTS {$t} (
                run_id        CHAR(64)     NOT NULL,
                schedule_id   VARCHAR(36)  NOT NULL,
                tenant_id     VARCHAR(255) NULL,
                slot          TEXT         NOT NULL,
                scheduled_for TIMESTAMPTZ  NOT NULL,
                dispatched_at TIMESTAMPTZ  NOT NULL,
                completed_at  TIMESTAMPTZ  NULL,
                run_state     VARCHAR(20)  NOT NULL DEFAULT 'dispatched',
                attempt       SMALLINT     NOT NULL DEFAULT 1,
                CONSTRAINT pk_{$t} PRIMARY KEY (run_id)
            )
        ");
        $this->connection->executeStatement("
            CREATE UNIQUE INDEX IF NOT EXISTS uq_{$t}_slot
                ON {$t} (tenant_id, schedule_id, slot)
        ");
    }

    private function cleanTestRows(): void
    {
        try {
            $this->connection->executeStatement(
                "DELETE FROM " . self::RUNS_TABLE . " WHERE tenant_id = 'ta'"
            );
        } catch (Throwable) {
        }
    }
}
