<?php

declare(strict_types=1);

namespace Vortos\Scheduler\DependencyInjection\Compiler;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Scheduler\Clock\ClockPort;
use Vortos\Scheduler\Observability\DeadManDetector;
use Vortos\Scheduler\Store\ScheduleCursorStoreInterface;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;

/**
 * Registers {@see DeadManDetector} once every package's extension has loaded.
 *
 * WHY A COMPILER PASS AND NOT SchedulerExtension::load()
 * ------------------------------------------------------
 * The detector needs `Vortos\Alerts\AlertDispatcherInterface`, which AlertsExtension registers as
 * a private alias in ITS load(). Symfony gives no ordering guarantee between extension load()
 * calls, so a `$container->has(AlertDispatcherInterface::class)` check inside SchedulerExtension
 * is a race against alphabetical/registration order — and it lost. In production the guard
 * evaluated false, the detector was never registered, `SchedulerDaemon::$deadMan` resolved to null
 * through its NULL_ON_INVALID_REFERENCE, and every schedule lost its overdue alarm silently.
 *
 * That is how 10 of 14 schedules sat dead for weeks while `scheduler:doctor` reported
 * "All checks passed" — the one component whose entire job is to notice a schedule that stopped
 * firing had itself been optimised out of existence.
 *
 * Compiler passes run strictly AFTER all extensions have loaded, so the availability check here is
 * a fact rather than a race. Cross-package wiring in this framework belongs in a pass for exactly
 * this reason; doing it in load() is only safe within a single package's own services.
 *
 * FAIL-LOUD CONTRACT
 * ------------------
 * When alerts genuinely are not installed the detector stays unregistered — that is legitimate
 * (a CLI-only or test container). What is NOT legitimate is that state going unnoticed in an
 * environment that believes it is monitored, so scheduler:doctor check C13 reports the detector's
 * presence explicitly instead of leaving it inferable only from an absence of alerts.
 */
final class DeadManDetectorPass implements CompilerPassInterface
{
    /**
     * Referenced as a string so this package never hard-depends on vortos-alerts being installed.
     */
    private const ALERT_DISPATCHER = 'Vortos\Alerts\AlertDispatcherInterface';

    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(DeadManDetector::class)) {
            return; // Already registered (explicit user override) — never clobber it.
        }

        if (!$container->has(self::ALERT_DISPATCHER)) {
            return; // vortos-alerts not installed or not configured; C13 will report this.
        }

        if (!$container->has(ScheduleRunStoreInterface::class)) {
            return; // No run store → no last-dispatch history to judge overdue-ness against.
        }

        $container->register(DeadManDetector::class, DeadManDetector::class)
            ->setArgument('$runStore',            new Reference(ScheduleRunStoreInterface::class))
            ->setArgument('$dispatcher',          new Reference(self::ALERT_DISPATCHER))
            ->setArgument('$clock',               new Reference(ClockPort::class))
            ->setArgument('$env',                 (string) ($_ENV['APP_ENV'] ?? 'production'))
            ->setArgument('$defaultToleranceSec', $container->hasParameter('vortos_scheduler.dead_man_tolerance_sec')
                ? $container->getParameter('vortos_scheduler.dead_man_tolerance_sec')
                : 300)
            ->setArgument('$logger',              new Reference(LoggerInterface::class))
            // Supplies the first-seen baseline that separates "never fired because it is new" from
            // "never fired because it is broken". NULL_ON_INVALID_REFERENCE rather than a hard
            // dependency: without it the detector still runs, and reports that distinction as
            // Indeterminate instead of guessing.
            ->setArgument('$cursors',             new Reference(
                ScheduleCursorStoreInterface::class,
                ContainerInterface::NULL_ON_INVALID_REFERENCE,
            ))
            ->setPublic(false);
    }
}
