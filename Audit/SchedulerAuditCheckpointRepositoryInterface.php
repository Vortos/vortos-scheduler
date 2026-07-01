<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Audit;

/**
 * PORT — persistence for scheduler audit chain checkpoints.
 */
interface SchedulerAuditCheckpointRepositoryInterface
{
    public function save(SchedulerAuditCheckpoint $checkpoint): void;

    /**
     * @return list<SchedulerAuditCheckpoint> in epoch ASC order
     */
    public function findByChainKey(string $chainKey, int $fromEpoch = 0): array;

    public function findLatest(string $chainKey): ?SchedulerAuditCheckpoint;
}
