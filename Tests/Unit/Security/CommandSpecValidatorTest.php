<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Security\CommandSpecValidator;
use Vortos\Scheduler\Security\Exception\CommandNotAllowlistedException;

final class CommandSpecValidatorTest extends TestCase
{
    public function test_allows_allowlisted_command(): void
    {
        $validator = new CommandSpecValidator(['App\Command\SendReportCommand' => true]);
        $spec      = new CommandSpec('App\Command\SendReportCommand');

        $validator->assert($spec); // must not throw
        $this->addToAssertionCount(1);
    }

    public function test_rejects_non_allowlisted_command(): void
    {
        $validator = new CommandSpecValidator(['App\Command\SendReportCommand' => true]);
        $spec      = new CommandSpec('App\Command\DangerousCommand');

        $this->expectException(CommandNotAllowlistedException::class);
        $this->expectExceptionMessage('App\Command\DangerousCommand');
        $validator->assert($spec);
    }

    public function test_rejects_command_when_allowlist_is_empty(): void
    {
        $validator = new CommandSpecValidator([]);
        $spec      = new CommandSpec('App\Command\AnyCommand');

        $this->expectException(CommandNotAllowlistedException::class);
        $validator->assert($spec);
    }

    public function test_is_allowlisted_returns_true_for_known_class(): void
    {
        $validator = new CommandSpecValidator(['App\Command\KnownCommand' => true]);

        self::assertTrue($validator->isAllowlisted('App\Command\KnownCommand'));
        self::assertFalse($validator->isAllowlisted('App\Command\Unknown'));
    }

    public function test_allowlisted_classes_returns_all_keys(): void
    {
        $validator = new CommandSpecValidator([
            'App\Command\Alpha' => true,
            'App\Command\Beta'  => true,
        ]);

        self::assertSame(
            ['App\Command\Alpha', 'App\Command\Beta'],
            $validator->allowlistedClasses(),
        );
    }

    public function test_exception_carries_command_class(): void
    {
        $validator = new CommandSpecValidator([]);
        $spec      = new CommandSpec('Acme\Scheduler\BannedCommand');

        try {
            $validator->assert($spec);
            self::fail('Expected CommandNotAllowlistedException');
        } catch (CommandNotAllowlistedException $e) {
            self::assertSame('Acme\Scheduler\BannedCommand', $e->commandClass);
        }
    }

    public function test_multiple_commands_in_allowlist_all_pass(): void
    {
        $validator = new CommandSpecValidator([
            'App\Command\Alpha' => true,
            'App\Command\Beta'  => true,
            'App\Command\Gamma' => true,
        ]);

        foreach (['App\Command\Alpha', 'App\Command\Beta', 'App\Command\Gamma'] as $class) {
            $validator->assert(new CommandSpec($class));
        }
        $this->addToAssertionCount(3);
    }
}
