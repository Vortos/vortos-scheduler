<?php

declare(strict_types=1);

namespace Vortos\Scheduler\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Scheduler\Registry\StaticScheduleDefinition;
use Vortos\Scheduler\Registry\StaticScheduleRegistry;
use Vortos\Scheduler\Schedule\Attribute\Scheduled;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\Trigger\OneShotTrigger;

/**
 * Discovers all StaticScheduleDefinition services at container-build time.
 *
 * Validated at compile time (hard error = container build failure):
 *  1. Tagged service class carries #[Scheduled] attribute.
 *  2. Tagged service class implements StaticScheduleDefinition.
 *  3. build() does not throw.
 *  4. build() returns source = ScheduleSource::Static.
 *  5. build() returns tenantId = null.
 *  6. RecurringTrigger / IntervalTrigger yields a future nextRunAfter(now).
 *     OneShotTrigger past its fire time is silently accepted.
 *  7. No duplicate schedule name among static definitions.
 *  8. No duplicate schedule ID among static definitions.
 *
 * On success: registers / updates StaticScheduleRegistry with the discovered class FQCNs.
 * On failure: throws \RuntimeException — the container build fails, blocking deployment.
 */
final class StaticSchedulePass implements CompilerPassInterface
{
    public const TAG = 'vortos_scheduler.static_schedule';

    public function process(ContainerBuilder $container): void
    {
        $taggedServices = $container->findTaggedServiceIds(self::TAG);

        $classes   = [];
        $seenNames = []; // name => FQCN
        $seenIds   = []; // id => FQCN

        foreach ($taggedServices as $serviceId => $tags) {
            $def   = $container->getDefinition($serviceId);
            $class = $def->getClass();

            if ($class === null || !\class_exists($class)) {
                throw new \RuntimeException(\sprintf(
                    'Static schedule service "%s" has no resolvable class. '
                    . 'Ensure the service definition includes a concrete class name.',
                    $serviceId,
                ));
            }

            $this->assertHasScheduledAttribute($class, $serviceId);
            $this->assertImplementsInterface($class, $serviceId);

            $schedule = $this->callBuild($class, $serviceId);

            $this->assertTenantIdNull($schedule, $class);
            $this->assertSourceStatic($schedule, $class);
            $this->assertTriggerYieldsFuture($schedule, $class);
            $this->assertNameUnique($schedule, $class, $seenNames);
            $this->assertIdUnique($schedule, $class, $seenIds);

            $seenNames[$schedule->name]             = $class;
            $seenIds[$schedule->id->toString()]     = $class;
            $classes[]                              = $class;
        }

        $this->registerRegistry($container, $classes);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Validation helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** @param class-string $class */
    private function assertHasScheduledAttribute(string $class, string $serviceId): void
    {
        $reflection = new \ReflectionClass($class);

        if (empty($reflection->getAttributes(Scheduled::class))) {
            throw new \RuntimeException(\sprintf(
                'Service "%s" (class %s) is tagged "%s" and implements StaticScheduleDefinition, '
                . 'but is missing the #[Scheduled] attribute. '
                . 'Add #[Scheduled] to the class to explicitly declare it as a static schedule definition.',
                $serviceId,
                $class,
                self::TAG,
            ));
        }
    }

    /** @param class-string $class */
    private function assertImplementsInterface(string $class, string $serviceId): void
    {
        if (!\is_a($class, StaticScheduleDefinition::class, true)) {
            throw new \RuntimeException(\sprintf(
                'Service "%s" (class %s) carries #[Scheduled] but does not implement %s. '
                . 'Implement the interface and provide a build(): Schedule method.',
                $serviceId,
                $class,
                StaticScheduleDefinition::class,
            ));
        }
    }

    /**
     * @param class-string<StaticScheduleDefinition> $class
     * @return \Vortos\Scheduler\Schedule\Schedule
     */
    private function callBuild(string $class, string $serviceId): \Vortos\Scheduler\Schedule\Schedule
    {
        try {
            return $class::build();
        } catch (\Throwable $e) {
            throw new \RuntimeException(\sprintf(
                'StaticScheduleDefinition "%s" (class %s) threw during build(): %s',
                $serviceId,
                $class,
                $e->getMessage(),
            ), 0, $e);
        }
    }

    /** @param class-string $class */
    private function assertTenantIdNull(\Vortos\Scheduler\Schedule\Schedule $schedule, string $class): void
    {
        if ($schedule->tenantId !== null) {
            throw new \RuntimeException(\sprintf(
                'Static schedule "%s" (class %s) returned tenantId = "%s". '
                . 'Static schedules must be system-scoped — build() must return tenantId = null.',
                $schedule->name,
                $class,
                $schedule->tenantId,
            ));
        }
    }

    /** @param class-string $class */
    private function assertSourceStatic(\Vortos\Scheduler\Schedule\Schedule $schedule, string $class): void
    {
        if ($schedule->source !== ScheduleSource::Static) {
            throw new \RuntimeException(\sprintf(
                'Static schedule "%s" (class %s) returned source = %s. '
                . 'StaticScheduleDefinition::build() must return source = ScheduleSource::Static.',
                $schedule->name,
                $class,
                $schedule->source->value,
            ));
        }
    }

    /** @param class-string $class */
    private function assertTriggerYieldsFuture(\Vortos\Scheduler\Schedule\Schedule $schedule, string $class): void
    {
        // OneShotTrigger past its fire time returns null — this is acceptable (the schedule
        // fired once and is permanently quiescent). All other trigger types must yield a future run.
        if ($schedule->trigger instanceof OneShotTrigger) {
            return;
        }

        $now  = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $next = $schedule->trigger->nextRunAfter($now);

        if ($next === null) {
            throw new \RuntimeException(\sprintf(
                'Static schedule "%s" (class %s): trigger::nextRunAfter(now) returned null for '
                . 'a non-OneShotTrigger. A recurring or interval trigger must always yield a future fire. '
                . 'Check the cron expression: "%s".',
                $schedule->name,
                $class,
                $schedule->trigger->describe(),
            ));
        }
    }

    /**
     * @param class-string $class
     * @param array<string, class-string> $seenNames
     */
    private function assertNameUnique(
        \Vortos\Scheduler\Schedule\Schedule $schedule,
        string $class,
        array $seenNames,
    ): void {
        if (isset($seenNames[$schedule->name])) {
            throw new \RuntimeException(\sprintf(
                'Duplicate static schedule name "%s": declared by both %s and %s. '
                . 'Schedule names must be unique across all static definitions.',
                $schedule->name,
                $seenNames[$schedule->name],
                $class,
            ));
        }
    }

    /**
     * @param class-string $class
     * @param array<string, class-string> $seenIds
     */
    private function assertIdUnique(
        \Vortos\Scheduler\Schedule\Schedule $schedule,
        string $class,
        array $seenIds,
    ): void {
        $idStr = $schedule->id->toString();

        if (isset($seenIds[$idStr])) {
            throw new \RuntimeException(\sprintf(
                'Duplicate static schedule ID "%s": declared by both %s and %s. '
                . 'Schedule IDs must be globally unique.',
                $idStr,
                $seenIds[$idStr],
                $class,
            ));
        }
    }

    /**
     * @param list<class-string<StaticScheduleDefinition>> $classes
     */
    private function registerRegistry(ContainerBuilder $container, array $classes): void
    {
        if ($container->hasDefinition(StaticScheduleRegistry::class)) {
            $container->getDefinition(StaticScheduleRegistry::class)
                ->setArgument('$definitionClasses', $classes);
        } else {
            $container->register(StaticScheduleRegistry::class, StaticScheduleRegistry::class)
                ->setArgument('$definitionClasses', $classes)
                ->setPublic(false);
        }
    }
}
