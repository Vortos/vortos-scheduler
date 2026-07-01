<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Security\Support;

use Vortos\Domain\Command\CommandInterface;

final class StubNonAllowlistedCommand implements CommandInterface
{
    public function idempotencyKey(): ?string
    {
        return null;
    }
}
