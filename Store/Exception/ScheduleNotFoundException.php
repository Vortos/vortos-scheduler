<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Store\Exception;

/**
 * Thrown when a required schedule cannot be found for the given (id, tenantId) scope.
 *
 * Using a domain exception (not RuntimeException) so callers can distinguish
 * "not found" from "infrastructure error" without relying on the message text.
 */
final class ScheduleNotFoundException extends \DomainException
{
    public function __construct(string $scheduleId, ?string $tenantId, ?\Throwable $previous = null)
    {
        $scope = $tenantId !== null ? "tenant '{$tenantId}'" : 'system scope';
        parent::__construct(
            "Schedule '{$scheduleId}' not found in {$scope}.",
            0,
            $previous,
        );
    }
}
