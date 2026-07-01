<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Testing;

use Vortos\Scheduler\Store\RunRetentionOverride;
use Vortos\Scheduler\Store\RunRetentionOverrideStoreInterface;

/**
 * Pure in-memory implementation of RunRetentionOverrideStoreInterface.
 * For use in unit tests that need the override store without a DB connection.
 */
final class InMemoryRunRetentionOverrideStore implements RunRetentionOverrideStoreInterface
{
    /** @var array<string, RunRetentionOverride> */
    private array $overrides = [];

    public function save(RunRetentionOverride $override): void
    {
        $this->overrides[$override->tenantId] = $override;
    }

    public function find(string $tenantId): ?RunRetentionOverride
    {
        return $this->overrides[$tenantId] ?? null;
    }

    public function remove(string $tenantId): void
    {
        unset($this->overrides[$tenantId]);
    }

    public function findAll(): array
    {
        return array_values($this->overrides);
    }

    /** Clear all overrides (test tearDown helper). */
    public function reset(): void
    {
        $this->overrides = [];
    }
}
