<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Schedule\Attribute;

/**
 * Marks a class as a static schedule definition.
 *
 * The marked class MUST implement StaticScheduleDefinition and provide a pure,
 * side-effect-free build(): Schedule method. The StaticSchedulePass validates
 * both the attribute presence and the build() output at container-build time.
 *
 * Rules enforced by StaticSchedulePass:
 *  - build() must return source = ScheduleSource::Static
 *  - build() must return tenantId = null  (static schedules are always system-scoped)
 *  - build() must return a unique name and ID across all static definitions
 *  - RecurringTrigger / IntervalTrigger must yield a future next run
 *
 * Static schedules are discovered once at compile time, zero runtime reflection.
 * Changing a schedule's name or ID between deploys resets its run history.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Scheduled {}
