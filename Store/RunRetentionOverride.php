<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Store;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Per-tenant override of the global `runRetentionDays` auto-prune setting.
 *
 * `retentionDays = 0` is a deliberate, distinct value from "use the global
 * default": it means this tenant is permanently exempt from auto-prune (legal
 * hold / extended compliance retention). It is set explicitly and audited, not
 * an accidental default. Negative values are invalid.
 */
final readonly class RunRetentionOverride
{
    public function __construct(
        public string             $tenantId,
        public int                $retentionDays,
        public string             $actorId,
        public DateTimeImmutable  $updatedAt,
    ) {
        if ($retentionDays < 0) {
            throw new InvalidArgumentException(
                sprintf('RunRetentionOverride: retentionDays must be >= 0, got %d.', $retentionDays),
            );
        }
    }

    /** True if this tenant is permanently exempt from auto-prune (legal hold). */
    public function isExempt(): bool
    {
        return $this->retentionDays === 0;
    }
}
