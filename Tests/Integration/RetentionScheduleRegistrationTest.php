<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Scheduler\Command\PruneSchedulerRunsCommand;
use Vortos\Scheduler\DependencyInjection\Compiler\StaticSchedulePass;
use Vortos\Scheduler\DependencyInjection\SchedulerExtension;
use Vortos\Scheduler\Registry\PruneSchedulerRunsSchedule;
use Vortos\Scheduler\Retention\RunRetentionSweeper;

/**
 * Verifies the auto-prune conditional-registration contract
 * (SCHEDULER_AUTO_PRUNE_IMPL_PLAN.md, design decision 2): `runRetentionDays = 0`
 * must skip registering PruneSchedulerRunsSchedule entirely — not register it as
 * a no-op — so it can never fire and grow the very table it exists to shrink.
 *
 * phpunit.xml sets SCHEDULER_RUN_RETENTION_DAYS=0 globally so every other test
 * that builds a full Scheduler container is unaffected by this schedule's
 * presence; these tests explicitly override it per-case and restore the
 * suite-wide default in tearDown() so the override never leaks into other tests.
 */
final class RetentionScheduleRegistrationTest extends TestCase
{
    protected function tearDown(): void
    {
        $_ENV['SCHEDULER_RUN_RETENTION_DAYS'] = '0';
    }

    public function test_retention_days_zero_does_not_register_prune_schedule(): void
    {
        $_ENV['SCHEDULER_RUN_RETENTION_DAYS'] = '0';
        $container = $this->buildContainer();

        self::assertFalse($container->hasDefinition(PruneSchedulerRunsSchedule::class));
    }

    public function test_retention_days_positive_registers_prune_schedule_tagged_for_static_schedule_pass(): void
    {
        $_ENV['SCHEDULER_RUN_RETENTION_DAYS'] = '30';
        $container = $this->buildContainer();

        self::assertTrue($container->hasDefinition(PruneSchedulerRunsSchedule::class));

        $tags = $container->getDefinition(PruneSchedulerRunsSchedule::class)->getTags();
        self::assertArrayHasKey(StaticSchedulePass::TAG, $tags);
    }

    public function test_retention_days_positive_also_registers_command_for_allowlist_discovery(): void
    {
        $_ENV['SCHEDULER_RUN_RETENTION_DAYS'] = '30';
        $container = $this->buildContainer();

        // PruneSchedulerRunsCommand is never fetched via the container — it must still
        // be registered as a definition so SchedulableCommandPass's attribute scan
        // (which only inspects already-registered definitions) can allowlist it.
        self::assertTrue($container->hasDefinition(PruneSchedulerRunsCommand::class));
    }

    public function test_sweeper_is_registered_regardless_of_retention_value(): void
    {
        $_ENV['SCHEDULER_RUN_RETENTION_DAYS'] = '0';
        $container = $this->buildContainer();

        // Shared by both the (disabled) automatic path and scheduler:prune's default mode.
        self::assertTrue($container->hasDefinition(RunRetentionSweeper::class));
    }

    private function buildContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();

        // SchedulerExtension::load() hard-requires these (same convention as
        // CacheExtension, AuthExtension, ... — see CacheExtensionEnvDefaultsTest).
        $container->setParameter('kernel.project_dir', sys_get_temp_dir() . '/missing_vortos_scheduler_config');
        $container->setParameter('kernel.env', 'test');

        $container->register(Connection::class, Connection::class)->setPublic(false);
        $container->register(LoggerInterface::class, NullLogger::class)->setPublic(false);

        (new SchedulerExtension())->load([], $container);

        return $container;
    }
}
