<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Registry;

use Vortos\Scheduler\Clock\ClockPort;
use Vortos\Scheduler\Schedule\Schedule;

/**
 * TTL-caching wrapper around {@see ScheduleResolver}.
 *
 * `ScheduleResolver::activeView()` executes a DB query on every daemon tick.
 * At 1 tick/second with 1000 dynamic schedules this is 86 400 queries/day per node
 * — for data that rarely changes. This cache reduces that cost by ~95 %
 * for the typical case (no schedule changed in the last `$ttlSec` seconds).
 *
 * Invalidation model:
 *   - `invalidate()` is called by `ScheduleService` after every mutation (create,
 *     update, pause, resume, delete). The service is the sole write path, so
 *     invalidation is always explicit and timely.
 *   - Even without explicit invalidation, the TTL ensures eventual consistency
 *     within `$ttlSec` seconds.
 *
 * Thread safety: this class is NOT thread-safe. It is per-process/per-daemon-instance,
 * which is the correct scope. Each PHP-FPM worker or daemon process has its own cache.
 *
 * When `$ttlSec === 0` the cache is disabled (every call delegates). Use this for
 * tests or apps that need sub-second schedule propagation.
 */
final class CachingScheduleResolver
{
    /** @var list<Schedule>|null */
    private ?array $cachedView = null;

    private ?\DateTimeImmutable $cachedAt = null;

    public function __construct(
        private readonly ScheduleResolver $inner,
        private readonly ClockPort        $clock,
        private readonly int              $ttlSec = 5,
    ) {}

    /**
     * @return iterable<Schedule>
     */
    public function activeView(): iterable
    {
        if ($this->ttlSec === 0) {
            return $this->inner->activeView();
        }

        $now = $this->clock->now();

        if ($this->cachedAt !== null
            && ($now->getTimestamp() - $this->cachedAt->getTimestamp()) < $this->ttlSec) {
            return $this->cachedView ?? [];
        }

        $this->cachedView = [...$this->inner->activeView()];
        $this->cachedAt   = $now;

        return $this->cachedView;
    }

    /**
     * @return iterable<Schedule>
     */
    public function fullView(?string $tenantId = null): iterable
    {
        return $this->inner->fullView($tenantId);
    }

    public function staticCount(): int
    {
        return $this->inner->staticCount();
    }

    public function hasStaticSchedules(): bool
    {
        return $this->inner->hasStaticSchedules();
    }

    public function getRegistry(): StaticScheduleRegistry
    {
        return $this->inner->getRegistry();
    }

    /**
     * Discard the cached view. Call this after any schedule mutation so the next
     * daemon tick sees fresh data rather than waiting for TTL expiry.
     */
    public function invalidate(): void
    {
        $this->cachedView = null;
        $this->cachedAt   = null;
    }
}
