<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Doctor;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\ResolveInstanceofConditionalsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Scheduler\Doctor\SchedulerPreflightCheck;

/**
 * Regression guard: when vortos-deploy is installed, SchedulerPreflightCheck must be collected
 * by DeployDoctor. The scheduler used to hand-tag the check with 'vortos.preflight_check' while
 * deploy collects 'vortos.deploy.preflight_check' — the gate was registered but never run.
 *
 * The scheduler now marks the check autoconfigured and relies on deploy's
 * registerForAutoconfiguration(PreflightCheckInterface) to apply the correct tag. This test
 * reproduces exactly that mechanism (deploy's autoconfiguration rule + the scheduler's
 * autoconfigured definition + Symfony's instanceof-resolution pass) and asserts the tag lands.
 */
final class SchedulerPreflightCheckAutoconfigurationTest extends TestCase
{
    private const DEPLOY_PREFLIGHT_TAG = 'vortos.deploy.preflight_check';

    public function test_autoconfiguration_applies_deploy_preflight_tag(): void
    {
        $container = new ContainerBuilder();

        // Mirror what DeployExtension::load() registers for preflight checks.
        $container->registerForAutoconfiguration(PreflightCheckInterface::class)
            ->addTag(self::DEPLOY_PREFLIGHT_TAG);

        // Mirror what SchedulerExtension registers when vortos-deploy is present.
        $container->register(SchedulerPreflightCheck::class, SchedulerPreflightCheck::class)
            ->setArgument('$doctor', new \Symfony\Component\DependencyInjection\Reference('scheduler.doctor'))
            ->setAutoconfigured(true)
            ->setPublic(true);

        (new ResolveInstanceofConditionalsPass())->process($container);

        $tags = $container->getDefinition(SchedulerPreflightCheck::class)->getTags();

        self::assertArrayHasKey(
            self::DEPLOY_PREFLIGHT_TAG,
            $tags,
            'SchedulerPreflightCheck must carry the tag DeployDoctor collects, or the gate never runs.',
        );
    }
}
