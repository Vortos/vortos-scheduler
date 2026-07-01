<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Security\Attribute;

/**
 * Marks a command class as safe to schedule via the Vortos Scheduler.
 *
 * Only classes carrying this attribute may appear in a CommandSpec.
 * The SchedulableCommandPass reads these at container build time and builds
 * the compile-time allowlist injected into CommandSpecValidator.
 *
 * Usage:
 *
 *   #[SchedulableCommand]
 *   final class SendDailyReportCommand { ... }
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class SchedulableCommand {}
