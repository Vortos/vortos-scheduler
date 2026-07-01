<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Security\Support;

use Vortos\Domain\Command\CommandInterface;
use Vortos\Scheduler\Security\Attribute\SchedulableCommand;

#[SchedulableCommand]
final class StubAllowlistedCommand implements CommandInterface
{
    public function idempotencyKey(): ?string
    {
        return null;
    }
}
