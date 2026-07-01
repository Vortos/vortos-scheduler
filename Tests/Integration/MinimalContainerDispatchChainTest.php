<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Vortos\Scheduler\DependencyInjection\SchedulerExtension;
use Vortos\Scheduler\DependencyInjection\SchedulerPackage;
use Vortos\Scheduler\Engine\CircuitBreaker\DispatchCircuitBreaker;
use Vortos\Scheduler\Engine\FireDispatcher;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Registry\StaticScheduleDefinition;
use Vortos\Scheduler\Schedule\Attribute\Scheduled;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;
use Vortos\Domain\Command\CommandInterface;
use Vortos\Scheduler\Security\Attribute\SchedulableCommand;
use Vortos\Scheduler\Security\CommandSpecValidator;
use Vortos\Scheduler\Service\ScheduleService;

/**
 * Regression test for a minimal-container DI gap: SchedulerExtension + SchedulerPackage's
 * compiler passes alone (i.e. no Symfony Console AddConsoleCommandPass, which a real app
 * kernel always provides) left ScheduleService — the package's only facade — with zero
 * incoming references, so Symfony deleted it outright at compile time. Any attempt to
 * fetch it threw ServiceNotFoundException, taking the whole dispatch chain
 * (FireDispatcher, CommandSpecValidator, ScheduleResolver, ...) down with it, since
 * nothing else in the container could reach them either.
 *
 * Reported against a real minimal-container test in a downstream app. Fixed by making
 * ScheduleService public in SchedulerExtension::registerService().
 */
final class MinimalContainerDispatchChainTest extends TestCase
{
    public function test_pre_fix_shape_throws_service_not_found(): void
    {
        $container = $this->buildContainer();

        // Simulates the pre-fix registration (setPublic(false)) to prove the failure
        // mode this test guards against is real, not hypothetical.
        $container->getDefinition(ScheduleService::class)->setPublic(false);
        $container->compile();
        $container->set(Connection::class, $this->createStub(Connection::class));

        $this->expectException(ServiceNotFoundException::class);
        $container->get(ScheduleService::class);
    }

    public function test_schedule_service_is_reachable_without_console_wiring(): void
    {
        $container = $this->buildContainer();
        $container->compile();
        $container->set(Connection::class, $this->createStub(Connection::class));

        $service = $container->get(ScheduleService::class);

        self::assertInstanceOf(ScheduleService::class, $service);
    }

    public function test_command_allowlist_validator_is_wired_without_console_wiring(): void
    {
        $container = $this->buildContainer();
        $container->compile();
        $container->set(Connection::class, $this->createStub(Connection::class));

        $service = $container->get(ScheduleService::class);

        $fireDispatcher = $this->readPrivateProperty($service, 'fireDispatcher');
        self::assertInstanceOf(DispatchCircuitBreaker::class, $fireDispatcher);

        $realDispatcher = $this->readPrivateProperty($fireDispatcher, 'inner');
        self::assertInstanceOf(FireDispatcher::class, $realDispatcher);

        $validator = $this->readPrivateProperty($realDispatcher, 'validator');
        self::assertInstanceOf(
            CommandSpecValidator::class,
            $validator,
            'CommandSpecValidator must be live inside FireDispatcher, or the allowlist '
            . 'guard silently no-ops in a minimal container.',
        );
    }

    private function readPrivateProperty(object $object, string $property): mixed
    {
        $ref  = new \ReflectionObject($object);
        $prop = $ref->getProperty($property);

        return $prop->getValue($object);
    }

    private function buildContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();

        // SchedulerExtension::load() hard-requires these (same convention as
        // CacheExtension, AuthExtension, ... — see CacheExtensionEnvDefaultsTest).
        $container->setParameter('kernel.project_dir', sys_get_temp_dir() . '/missing_vortos_scheduler_config');
        $container->setParameter('kernel.env', 'test');

        $container->register(Connection::class, Connection::class)
            ->setPublic(true)
            ->setSynthetic(true);
        $container->register(LoggerInterface::class, NullLogger::class)->setPublic(false);

        (new SchedulerExtension())->load([], $container);
        (new SchedulerPackage())->build($container);

        $container->register(RetentionPruneCommandFixture::class, RetentionPruneCommandFixture::class)
            ->setPublic(false);
        $container->register(RetentionPruneScheduleFixture::class, RetentionPruneScheduleFixture::class)
            ->setPublic(true)
            ->addTag('vortos_scheduler.static_schedule');

        return $container;
    }

    protected function tearDown(): void
    {
        // Nothing to clean up — each test builds its own ContainerBuilder.
    }
}

#[SchedulableCommand]
final class RetentionPruneCommandFixture implements CommandInterface
{
    public function idempotencyKey(): ?string
    {
        return null;
    }
}

#[Scheduled]
final class RetentionPruneScheduleFixture implements StaticScheduleDefinition
{
    public static function build(): Schedule
    {
        return new Schedule(
            id:       ScheduleId::fromString('00000000-0000-4000-8000-000000000099'),
            name:     'minimal-container-fixture-schedule',
            source:   ScheduleSource::Static,
            trigger:  new IntervalTrigger(3600),
            command:  new CommandSpec(RetentionPruneCommandFixture::class),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,
        );
    }
}
