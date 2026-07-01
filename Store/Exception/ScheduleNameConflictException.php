<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Store\Exception;

/**
 * Thrown when saving a schedule would create a duplicate (tenantId, name) pair.
 *
 * The unique constraint is enforced at the DB level (UNIQUE index) and at the
 * application layer. This exception surfaces the DB violation as a typed domain
 * exception so callers don't need to inspect DBAL exception messages.
 */
final class ScheduleNameConflictException extends \DomainException
{
    public function __construct(string $name, ?string $tenantId, ?\Throwable $previous = null)
    {
        $scope = $tenantId !== null ? "tenant '{$tenantId}'" : 'system scope';
        parent::__construct(
            "A schedule named '{$name}' already exists in {$scope}.",
            0,
            $previous,
        );
    }
}
