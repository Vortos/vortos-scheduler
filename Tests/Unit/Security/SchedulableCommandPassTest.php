<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Vortos\Scheduler\DependencyInjection\Compiler\SchedulableCommandPass;
use Vortos\Scheduler\Engine\FireDispatcher;
use Vortos\Scheduler\Security\Attribute\SchedulableCommand;
use Vortos\Scheduler\Security\CommandSpecValidator;
use Vortos\Scheduler\Tests\Unit\Security\Support\StubAllowlistedCommand;
use Vortos\Scheduler\Tests\Unit\Security\Support\StubNonAllowlistedCommand;

final class SchedulableCommandPassTest extends TestCase
{
    // ── No allowlist — validator not activated ─────────────────────────────

    public function test_skips_validator_registration_when_no_allowlisted_commands(): void
    {
        $container = new ContainerBuilder();

        (new SchedulableCommandPass())->process($container);

        self::assertFalse($container->hasDefinition(CommandSpecValidator::class));
    }

    // ── Attribute-based discovery ─────────────────────────────────────────

    public function test_registers_validator_when_attribute_command_is_in_container(): void
    {
        $container = $this->containerWithCommand(StubAllowlistedCommand::class);

        (new SchedulableCommandPass())->process($container);

        self::assertTrue($container->hasDefinition(CommandSpecValidator::class));
    }

    public function test_allowlist_contains_only_attribute_tagged_class(): void
    {
        $container = $this->containerWithCommands([
            StubAllowlistedCommand::class,
            StubNonAllowlistedCommand::class,
        ]);

        (new SchedulableCommandPass())->process($container);

        $allowlist = $container->getDefinition(CommandSpecValidator::class)->getArgument('$allowlist');

        self::assertArrayHasKey(StubAllowlistedCommand::class, $allowlist);
        self::assertArrayNotHasKey(StubNonAllowlistedCommand::class, $allowlist);
    }

    // ── Tag-based discovery ───────────────────────────────────────────────

    public function test_registers_tagged_service_in_allowlist(): void
    {
        $container = new ContainerBuilder();
        $def       = new Definition(StubNonAllowlistedCommand::class);
        $def->addTag(SchedulableCommandPass::TAG);
        $container->setDefinition(StubNonAllowlistedCommand::class, $def);

        (new SchedulableCommandPass())->process($container);

        $allowlist = $container->getDefinition(CommandSpecValidator::class)->getArgument('$allowlist');
        self::assertArrayHasKey(StubNonAllowlistedCommand::class, $allowlist);
    }

    // ── FireDispatcher injection ──────────────────────────────────────────

    public function test_injects_validator_into_fire_dispatcher_when_present(): void
    {
        $container = $this->containerWithCommand(StubAllowlistedCommand::class);

        $dispatcherDef = new Definition(FireDispatcher::class);
        $container->setDefinition(FireDispatcher::class, $dispatcherDef);

        (new SchedulableCommandPass())->process($container);

        $validatorArg = $container->getDefinition(FireDispatcher::class)->getArgument('$validator');
        self::assertNotNull($validatorArg);
    }

    public function test_skips_fire_dispatcher_injection_when_dispatcher_absent(): void
    {
        $container = $this->containerWithCommand(StubAllowlistedCommand::class);

        (new SchedulableCommandPass())->process($container);

        // Should not throw — FireDispatcher simply wasn't registered
        self::assertTrue($container->hasDefinition(CommandSpecValidator::class));
        self::assertFalse($container->hasDefinition(FireDispatcher::class));
    }

    // ── TAG constant ─────────────────────────────────────────────────────

    public function test_tag_constant_value(): void
    {
        self::assertSame('vortos.schedulable_command', SchedulableCommandPass::TAG);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function containerWithCommand(string $class): ContainerBuilder
    {
        return $this->containerWithCommands([$class]);
    }

    /** @param list<class-string> $classes */
    private function containerWithCommands(array $classes): ContainerBuilder
    {
        $container = new ContainerBuilder();
        foreach ($classes as $class) {
            $container->setDefinition($class, new Definition($class));
        }
        return $container;
    }
}
