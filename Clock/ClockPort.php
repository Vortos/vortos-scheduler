<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Clock;

use Psr\Clock\ClockInterface;

/**
 * PSR-20 clock seam for the scheduler package.
 *
 * Extends PSR-20 so any PSR-20-aware container wiring satisfies this interface.
 * Kept as a distinct type so architecture tests can enforce that the engine only
 * accesses time through this seam — not through bare PHP date/time functions.
 */
interface ClockPort extends ClockInterface {}
