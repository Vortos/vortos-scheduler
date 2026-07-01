<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Engine\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\UuidV7;
use Vortos\Scheduler\Engine\SchedulerEnqueuerPort;
use Vortos\Scheduler\Fire\IdempotencyKey;
use Vortos\Scheduler\Fire\RunStamp;
use Vortos\Scheduler\Fire\ScheduledFire;
use Vortos\Scheduler\Schedule\Schedule;

/**
 * DBAL driver for SchedulerEnqueuerPort.
 *
 * Writes a fire record to vortos_scheduler_fire_queue within the caller's
 * active DBAL transaction (FireDispatcher's BEGIN…COMMIT). Never starts its own
 * transaction — atomicity with insertRun() is the caller's responsibility.
 *
 * The fire queue is separate from messaging_outbox so that relay workers never
 * attempt to route scheduler rows to a broker transport.
 *
 * metadata column stores RunStamp headers (X-Scheduler-*) as JSON so the S5
 * daemon can reconstruct them as HeadersStamp when dispatching in-process.
 */
final class DbalSchedulerEnqueuer implements SchedulerEnqueuerPort
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string     $table = 'vortos_scheduler_fire_queue',
    ) {}

    public function enqueue(ScheduledFire $fire, Schedule $schedule): void
    {
        $runId = IdempotencyKey::fromSlotKey($fire->slot)->value;

        $stamp = new RunStamp(
            runId:      $runId,
            scheduleId: $fire->scheduleId->toString(),
            slot:       $fire->slot,
            tenantId:   $fire->tenantId,
        );

        $this->connection->insert($this->table, [
            'id'              => (new UuidV7())->toRfc4122(),
            'run_id'          => $runId,
            'schedule_id'     => $fire->scheduleId->toString(),
            'tenant_id'       => $fire->tenantId,
            'slot'            => $fire->slot,
            'scheduled_for'   => $this->utc($fire->scheduledFor),
            'command_class'   => $schedule->command->commandClass,
            'command_payload' => json_encode($schedule->command->payload, JSON_THROW_ON_ERROR),
            'metadata'        => json_encode($stamp->toHeaders(), JSON_THROW_ON_ERROR),
            'status'          => 'pending',
            'created_at'      => $this->utc(new DateTimeImmutable()),
            'dispatched_at'   => null,
            'failure_reason'  => null,
        ]);
    }

    private function utc(DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
