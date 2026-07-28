<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Audit;

/**
 * In-memory checkpoint repository for unit tests.
 */
final class InMemorySchedulerAuditCheckpointRepository implements SchedulerAuditCheckpointRepositoryInterface
{
    /** @var list<SchedulerAuditCheckpoint> */
    private array $checkpoints = [];

    public function save(SchedulerAuditCheckpoint $checkpoint): void
    {
        $this->checkpoints[] = $checkpoint;
    }

    public function findByChainKey(string $chainKey, int $fromEpoch = 0): array
    {
        return array_values(array_filter(
            $this->checkpoints,
            fn(SchedulerAuditCheckpoint $c) => $c->chainKey === $chainKey && $c->epoch >= $fromEpoch,
        ));
    }

    public function findLatest(string $chainKey): ?SchedulerAuditCheckpoint
    {
        $matches = $this->findByChainKey($chainKey);

        if ($matches === []) {
            return null;
        }

        // end() would be `?: null`-guarded against a `false` that cannot happen here — and `?:` would
        // also swallow a legitimately falsy checkpoint if this list ever held anything but objects.
        return $matches[array_key_last($matches)];
    }

    /** @return list<SchedulerAuditCheckpoint> */
    public function all(): array
    {
        return $this->checkpoints;
    }
}
