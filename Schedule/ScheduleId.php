<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Schedule;

use Vortos\Domain\Identity\AggregateId;

/**
 * Typed identity for a Schedule.
 * Extends AggregateId for UuidV7 generation and validated fromString() reconstruction,
 * consistent with the rest of the framework (UserId, OrderId, etc.).
 */
final class ScheduleId extends AggregateId {}
