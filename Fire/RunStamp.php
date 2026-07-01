<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Fire;

/**
 * Stamp encoded into the fire-queue row's `metadata` column by DbalSchedulerEnqueuer.
 * FireQueueConsumer (S12) decodes these headers to locate and transition the
 * ledger row after CommandBus dispatch completes.
 *
 * Header constants are the single source of truth — no magic strings scattered
 * across DbalSchedulerEnqueuer and FireQueueConsumer.
 */
final readonly class RunStamp
{
    public const HEADER_RUN_ID      = 'X-Scheduler-Run-Id';
    public const HEADER_SCHEDULE_ID = 'X-Scheduler-Schedule-Id';
    public const HEADER_SLOT        = 'X-Scheduler-Slot';
    public const HEADER_TENANT_ID   = 'X-Scheduler-Tenant-Id';

    public function __construct(
        public string  $runId,      // IdempotencyKey->value (sha256 hex, 64 chars)
        public string  $scheduleId, // UUID string
        public string  $slot,       // human-readable slot key
        public ?string $tenantId,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toHeaders(): array
    {
        $headers = [
            self::HEADER_RUN_ID      => $this->runId,
            self::HEADER_SCHEDULE_ID => $this->scheduleId,
            self::HEADER_SLOT        => $this->slot,
        ];

        if ($this->tenantId !== null) {
            $headers[self::HEADER_TENANT_ID] = $this->tenantId;
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $headers
     */
    public static function fromHeaders(array $headers): ?self
    {
        $runId = isset($headers[self::HEADER_RUN_ID]) ? (string) $headers[self::HEADER_RUN_ID] : '';

        if ($runId === '') {
            return null;
        }

        $tenantId = isset($headers[self::HEADER_TENANT_ID]) && (string) $headers[self::HEADER_TENANT_ID] !== ''
            ? (string) $headers[self::HEADER_TENANT_ID]
            : null;

        return new self(
            runId:      $runId,
            scheduleId: isset($headers[self::HEADER_SCHEDULE_ID]) ? (string) $headers[self::HEADER_SCHEDULE_ID] : '',
            slot:       isset($headers[self::HEADER_SLOT]) ? (string) $headers[self::HEADER_SLOT] : '',
            tenantId:   $tenantId,
        );
    }
}
