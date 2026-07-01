<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Schedule\Policy;

use InvalidArgumentException;

/**
 * Sealed hierarchy for misfire behaviour when the daemon was down and has gaps to recover.
 *
 * PHP enums cannot carry per-instance data, so FireEachMissed(cap) requires a class.
 * Instantiate via the static factories only:
 *
 *   MisfirePolicy::skipMissed()
 *   MisfirePolicy::fireOnceNow()
 *   MisfirePolicy::fireEachMissed(cap: 100)
 */
abstract readonly class MisfirePolicy
{
    final public static function skipMissed(): SkipMissed
    {
        return new SkipMissed();
    }

    final public static function fireOnceNow(): FireOnceNow
    {
        return new FireOnceNow();
    }

    final public static function fireEachMissed(int $cap = FireEachMissed::DEFAULT_CAP): FireEachMissed
    {
        return new FireEachMissed($cap);
    }

    /** Stable serializable key for persistence and audit. */
    abstract public function key(): string;
}

final readonly class SkipMissed extends MisfirePolicy
{
    public function key(): string
    {
        return 'skip_missed';
    }
}

final readonly class FireOnceNow extends MisfirePolicy
{
    public function key(): string
    {
        return 'fire_once_now';
    }
}

final readonly class FireEachMissed extends MisfirePolicy
{
    public const DEFAULT_CAP = 100;
    public const MIN_CAP = 1;
    public const MAX_CAP = 1000;

    public function __construct(public readonly int $cap = self::DEFAULT_CAP)
    {
        if ($cap < self::MIN_CAP || $cap > self::MAX_CAP) {
            throw new InvalidArgumentException(
                sprintf(
                    'FireEachMissed cap must be %d–%d, got %d.',
                    self::MIN_CAP,
                    self::MAX_CAP,
                    $cap,
                )
            );
        }
    }

    public function key(): string
    {
        return 'fire_each_missed';
    }
}
