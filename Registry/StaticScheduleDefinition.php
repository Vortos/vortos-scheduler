<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Registry;

use Vortos\Scheduler\Schedule\Schedule;

/**
 * Contract for compile-time static schedule declarations.
 *
 * Implementing classes must also carry #[Scheduled] — the attribute is the
 * explicit intent marker; the interface provides the build() seam.
 *
 * build() is static so it can be called without instantiation:
 *  - StaticSchedulePass calls $class::build() at container-build time.
 *  - StaticScheduleRegistry calls $class::build() once at runtime (cached).
 *
 * build() MUST be pure:
 *  - Deterministic: same output every call.
 *  - No I/O: no DB queries, no HTTP calls, no file reads.
 *  - No side effects: no global state mutations.
 *
 * Contracts enforced by StaticSchedulePass (violation = container build failure):
 *  - Returned Schedule::$tenantId must be null.
 *  - Returned Schedule::$source must be ScheduleSource::Static.
 *  - Name and ID must be unique across all static definitions.
 *  - RecurringTrigger / IntervalTrigger must yield a non-null nextRunAfter(now).
 *
 * Runtime enforcement by StaticScheduleRegistry (defence in depth):
 *  - Validates tenantId and source again on first all() call.
 */
interface StaticScheduleDefinition
{
    public static function build(): Schedule;
}
