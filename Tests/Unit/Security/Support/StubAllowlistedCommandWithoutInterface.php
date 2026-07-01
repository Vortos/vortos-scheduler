<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Security\Support;

use Vortos\Scheduler\Security\Attribute\SchedulableCommand;

/**
 * Carries #[SchedulableCommand] but deliberately does NOT implement CommandInterface —
 * used to prove SchedulableCommandPass rejects this shape at compile time (S12).
 */
#[SchedulableCommand]
final class StubAllowlistedCommandWithoutInterface {}
