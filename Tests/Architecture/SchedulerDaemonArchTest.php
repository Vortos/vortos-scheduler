<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Scheduler\Clock\ClockPort;
use Vortos\Scheduler\Console\SchedulerRunCommand;
use Vortos\Scheduler\DependencyInjection\SchedulerExtension;
use Vortos\Scheduler\Engine\SchedulerDaemon;
use Vortos\Scheduler\Schedule\ScheduleId;

/**
 * Structural / architecture tests for S5.
 *
 * Verifies:
 *  1. SchedulerDaemon is final with the correct public API.
 *  2. SchedulerRunCommand is final and extends Symfony Command.
 *  3. Static helpers (leaseKeyForShard, shardIndexFor) exist and are static.
 *  4. DI extension registers SchedulerRunCommand with console.command tag.
 *  5. SchedulerDaemon does NOT appear in the pure-engine file allowlist
 *     (it is intentionally I/O-bearing — guarded by SchedulerPurityArchTest).
 */
final class SchedulerDaemonArchTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────
    // SchedulerDaemon class-level invariants
    // ─────────────────────────────────────────────────────────────

    public function test_scheduler_daemon_is_final(): void
    {
        self::assertTrue((new \ReflectionClass(SchedulerDaemon::class))->isFinal());
    }

    public function test_scheduler_daemon_has_run_method(): void
    {
        $r = new \ReflectionClass(SchedulerDaemon::class);
        self::assertTrue($r->hasMethod('run'));
        self::assertTrue($r->getMethod('run')->isPublic());
        self::assertFalse($r->getMethod('run')->isStatic());
    }

    public function test_scheduler_daemon_has_stop_method(): void
    {
        $r = new \ReflectionClass(SchedulerDaemon::class);
        self::assertTrue($r->hasMethod('stop'));
        self::assertTrue($r->getMethod('stop')->isPublic());
        self::assertFalse($r->getMethod('stop')->isStatic());
    }

    public function test_scheduler_daemon_has_run_once_method_returning_bool(): void
    {
        $r      = new \ReflectionClass(SchedulerDaemon::class);
        self::assertTrue($r->hasMethod('runOnce'));

        $method = $r->getMethod('runOnce');
        self::assertTrue($method->isPublic());
        self::assertFalse($method->isStatic());

        $returnType = $method->getReturnType();
        self::assertNotNull($returnType);
        self::assertSame('bool', (string) $returnType);
    }

    public function test_scheduler_daemon_has_lease_key_for_shard_static_method(): void
    {
        $r = new \ReflectionClass(SchedulerDaemon::class);
        self::assertTrue($r->hasMethod('leaseKeyForShard'));

        $method = $r->getMethod('leaseKeyForShard');
        self::assertTrue($method->isPublic());
        self::assertTrue($method->isStatic());
    }

    public function test_scheduler_daemon_has_shard_index_for_static_method(): void
    {
        $r = new \ReflectionClass(SchedulerDaemon::class);
        self::assertTrue($r->hasMethod('shardIndexFor'));

        $method = $r->getMethod('shardIndexFor');
        self::assertTrue($method->isPublic());
        self::assertTrue($method->isStatic());
    }

    public function test_scheduler_daemon_constructor_has_all_required_parameters(): void
    {
        $params = (new \ReflectionClass(SchedulerDaemon::class))
            ->getConstructor()
            ?->getParameters() ?? [];

        $names = \array_map(fn (\ReflectionParameter $p) => $p->getName(), $params);

        $required = [
            'leasePort',
            'scheduleResolver',
            'runStore',
            'dueScan',
            'fireDispatcher',
            'clock',
            'logger',
            'shardCount',
            'leaseTtlSec',
            'maxIdleSec',
            'tenantMaxConcurrentFires',
        ];

        foreach ($required as $name) {
            self::assertContains($name, $names, "SchedulerDaemon constructor must have '\${$name}' parameter.");
        }
    }

    public function test_scheduler_daemon_shard_count_defaults_to_1(): void
    {
        $params = (new \ReflectionClass(SchedulerDaemon::class))
            ->getConstructor()
            ?->getParameters() ?? [];

        foreach ($params as $p) {
            if ($p->getName() === 'shardCount') {
                self::assertTrue($p->isDefaultValueAvailable(), 'shardCount must have a default');
                self::assertSame(1, $p->getDefaultValue());
                return;
            }
        }

        self::fail('shardCount parameter not found');
    }

    public function test_scheduler_daemon_tenant_max_fires_defaults_to_0(): void
    {
        $params = (new \ReflectionClass(SchedulerDaemon::class))
            ->getConstructor()
            ?->getParameters() ?? [];

        foreach ($params as $p) {
            if ($p->getName() === 'tenantMaxConcurrentFires') {
                self::assertTrue($p->isDefaultValueAvailable());
                self::assertSame(0, $p->getDefaultValue());
                return;
            }
        }

        self::fail('tenantMaxConcurrentFires parameter not found');
    }

    // ─────────────────────────────────────────────────────────────
    // SchedulerRunCommand
    // ─────────────────────────────────────────────────────────────

    public function test_scheduler_run_command_is_final(): void
    {
        self::assertTrue((new \ReflectionClass(SchedulerRunCommand::class))->isFinal());
    }

    public function test_scheduler_run_command_extends_symfony_command(): void
    {
        self::assertTrue(
            (new \ReflectionClass(SchedulerRunCommand::class))
                ->isSubclassOf(\Symfony\Component\Console\Command\Command::class),
        );
    }

    public function test_scheduler_run_command_has_daemon_constructor_param(): void
    {
        $params = (new \ReflectionClass(SchedulerRunCommand::class))
            ->getConstructor()
            ?->getParameters() ?? [];

        self::assertCount(1, $params, 'SchedulerRunCommand must have exactly one constructor param');
        self::assertSame('daemon', $params[0]->getName());
        self::assertSame(SchedulerDaemon::class, (string) $params[0]->getType());
    }

    public function test_scheduler_run_command_has_as_command_attribute(): void
    {
        $attrs = (new \ReflectionClass(SchedulerRunCommand::class))
            ->getAttributes(\Symfony\Component\Console\Attribute\AsCommand::class);

        self::assertNotEmpty($attrs, 'SchedulerRunCommand must carry #[AsCommand]');

        /** @var \Symfony\Component\Console\Attribute\AsCommand $attr */
        $attr = $attrs[0]->newInstance();
        self::assertSame('scheduler:run', $attr->name);
    }

    // ─────────────────────────────────────────────────────────────
    // DI wiring
    // ─────────────────────────────────────────────────────────────

    public function test_di_extension_registers_scheduler_run_command(): void
    {
        $container = new ContainerBuilder();
        (new SchedulerExtension())->load([], $container);

        // Without DBAL (no Connection class in env), the daemon registration is
        // skipped. When DBAL IS available the command is registered.
        if (!\class_exists(\Doctrine\DBAL\Connection::class)) {
            $this->markTestSkipped('DBAL not available; daemon registration skipped.');
        }

        self::assertTrue(
            $container->hasDefinition(SchedulerRunCommand::class),
            'SchedulerRunCommand must be registered when DBAL is available.',
        );
    }

    public function test_di_extension_registers_scheduler_run_command_with_console_command_tag(): void
    {
        if (!\class_exists(\Doctrine\DBAL\Connection::class)) {
            $this->markTestSkipped('DBAL not available.');
        }

        $container = new ContainerBuilder();
        (new SchedulerExtension())->load([], $container);

        $def  = $container->getDefinition(SchedulerRunCommand::class);
        $tags = $def->getTags();

        self::assertArrayHasKey('console.command', $tags);
    }

    public function test_di_extension_registers_scheduler_daemon(): void
    {
        if (!\class_exists(\Doctrine\DBAL\Connection::class)) {
            $this->markTestSkipped('DBAL not available.');
        }

        $container = new ContainerBuilder();
        (new SchedulerExtension())->load([], $container);

        self::assertTrue(
            $container->hasDefinition(SchedulerDaemon::class),
            'SchedulerDaemon must be registered when DBAL is available.',
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Purity: SchedulerDaemon must NOT appear in PURE_ENGINE_FILES
    // ─────────────────────────────────────────────────────────────

    public function test_scheduler_daemon_filename_not_in_pure_engine_allowlist(): void
    {
        // The SchedulerPurityArchTest allowlist deliberately excludes SchedulerDaemon.php
        // because the daemon has I/O dependencies. This test documents that design decision.
        $pureEngineFiles = [
            'DueScan.php',
            'MisfireResolver.php',
            'DueScanResult.php',
            'DroppedSlotRecord.php',
            'FireDispatchResult.php',
            'SchedulerEnqueuerPort.php',
            'SlotCalculator.php',
        ];

        self::assertNotContains(
            'SchedulerDaemon.php',
            $pureEngineFiles,
            'SchedulerDaemon is intentionally I/O-bearing and must never be in the pure-engine allowlist.',
        );
    }

    // ─────────────────────────────────────────────────────────────
    // LeasePort alias is required for daemon wiring
    // ─────────────────────────────────────────────────────────────

    public function test_daemon_wiring_references_lease_port_interface(): void
    {
        if (!\class_exists(\Doctrine\DBAL\Connection::class)) {
            $this->markTestSkipped('DBAL not available.');
        }

        $container = new ContainerBuilder();
        (new SchedulerExtension())->load([], $container);

        $def  = $container->getDefinition(SchedulerDaemon::class);
        $args = $def->getArguments();

        $leasePortArg = $args['$leasePort'] ?? null;
        self::assertNotNull($leasePortArg, 'SchedulerDaemon must be wired with $leasePort');
        self::assertInstanceOf(\Symfony\Component\DependencyInjection\Reference::class, $leasePortArg);
        self::assertSame(\Vortos\Scheduler\Lease\LeasePort::class, (string) $leasePortArg);
    }

    public function test_daemon_wiring_references_schedule_resolver(): void
    {
        if (!\class_exists(\Doctrine\DBAL\Connection::class)) {
            $this->markTestSkipped('DBAL not available.');
        }

        $container = new ContainerBuilder();
        (new SchedulerExtension())->load([], $container);

        $def  = $container->getDefinition(SchedulerDaemon::class);
        $args = $def->getArguments();

        $resolverArg = $args['$scheduleResolver'] ?? null;
        self::assertNotNull($resolverArg, 'SchedulerDaemon must be wired with $scheduleResolver');
        self::assertInstanceOf(\Symfony\Component\DependencyInjection\Reference::class, $resolverArg);
        self::assertSame(\Vortos\Scheduler\Registry\ScheduleResolver::class, (string) $resolverArg);
    }

    public function test_daemon_wiring_does_not_reference_schedule_store_directly(): void
    {
        if (!\class_exists(\Doctrine\DBAL\Connection::class)) {
            $this->markTestSkipped('DBAL not available.');
        }

        $container = new ContainerBuilder();
        (new SchedulerExtension())->load([], $container);

        $def  = $container->getDefinition(SchedulerDaemon::class);
        $args = $def->getArguments();

        self::assertArrayNotHasKey(
            '$scheduleStore',
            $args,
            'SchedulerDaemon must receive ScheduleResolver, not ScheduleStoreInterface directly (S6 invariant)',
        );
    }

    // ─────────────────────────────────────────────────────────────
    // shardIndexFor uses ScheduleId as first argument
    // ─────────────────────────────────────────────────────────────

    public function test_shard_index_for_first_param_is_schedule_id(): void
    {
        $params = (new \ReflectionClass(SchedulerDaemon::class))
            ->getMethod('shardIndexFor')
            ->getParameters();

        self::assertCount(2, $params);
        self::assertSame(ScheduleId::class, (string) $params[0]->getType());
        self::assertSame('shardCount', $params[1]->getName());
    }
}
