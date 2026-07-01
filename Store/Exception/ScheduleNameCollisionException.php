<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Store\Exception;

/**
 * Thrown by ScheduleResolver when a dynamic schedule's name or ID collides
 * with a statically-declared schedule.
 *
 * Name collision: same name AND same tenant scope (both tenantId=null).
 * ID collision: same ID regardless of tenant.
 *
 * Collisions are a configuration error that must be resolved before the next
 * deploy. Run scheduler:doctor (S9) to audit. The SchedulerDaemon outer
 * try/catch treats this as a systemic tick failure and applies exponential backoff.
 */
final class ScheduleNameCollisionException extends \RuntimeException {}
