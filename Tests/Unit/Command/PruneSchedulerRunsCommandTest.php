<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Vortos\Domain\Command\CommandInterface;
use Vortos\Scheduler\Command\PruneSchedulerRunsCommand;
use Vortos\Scheduler\Security\Attribute\SchedulableCommand;

final class PruneSchedulerRunsCommandTest extends TestCase
{
    public function test_implements_command_interface(): void
    {
        self::assertInstanceOf(CommandInterface::class, new PruneSchedulerRunsCommand());
    }

    public function test_idempotency_key_is_null(): void
    {
        self::assertNull((new PruneSchedulerRunsCommand())->idempotencyKey());
    }

    public function test_carries_schedulable_command_attribute(): void
    {
        $attrs = (new \ReflectionClass(PruneSchedulerRunsCommand::class))->getAttributes(SchedulableCommand::class);
        self::assertNotEmpty($attrs);
    }
}
