<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Security\Support;

use Vortos\Scheduler\Security\Attribute\SchedulableCommand;

#[SchedulableCommand]
final class StubAllowlistedCommand {}
