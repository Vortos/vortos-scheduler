<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Engine\Consumer;

/**
 * When a long-lived `scheduler:consume --loop` process should exit so its supervisor restarts it.
 *
 * A PHP CLI worker that reaches `memory_limit` does not stop — it dies on a fatal in the middle of
 * whatever it was doing. For the fire-queue consumer that is mid-batch, holding an open command
 * transaction, with the rest of the claimed batch left in `processing` (FB-64). The only reliable
 * defence is never to get there: check between batches, exit cleanly while there is still headroom,
 * and let supervisord (`autorestart=true`) start a fresh process on a clean heap.
 *
 * Time recycles too, independently of memory, because slow leaks and stale state — a rotated
 * credential, a DNS change behind a persistent connection — do not always show up as bytes.
 *
 * Same contract as Symfony Messenger's `--memory-limit` / `--time-limit`, with one difference: the
 * memory threshold defaults to a fraction of the process's own `memory_limit`, so the guard follows
 * the ini value instead of a number someone has to keep in step with it.
 */
final readonly class WorkerRecyclePolicy
{
    /** Share of `memory_limit` at which an auto-configured worker recycles. */
    public const AUTO_MEMORY_FRACTION = 0.8;

    public function __construct(
        public ?int $memoryLimitBytes,
        public ?int $timeLimitSeconds,
        public float $startedAt,
    ) {}

    /**
     * @param int    $memoryLimitMib 0 = auto (a fraction of $iniMemoryLimit), negative = never, >0 = MiB
     * @param int    $timeLimitSec   0 or negative = never
     * @param string $iniMemoryLimit the process's `memory_limit`, e.g. "256M" or "-1"
     */
    public static function fromOptions(int $memoryLimitMib, int $timeLimitSec, string $iniMemoryLimit, float $startedAt): self
    {
        $ceiling = self::parseIniBytes($iniMemoryLimit);
        $auto    = $ceiling === null ? null : (int) floor($ceiling * self::AUTO_MEMORY_FRACTION);

        $memory = match (true) {
            $memoryLimitMib < 0   => null,
            $memoryLimitMib === 0 => $auto,
            // A threshold at or above the hard ceiling can never fire: the process dies on the fatal
            // first. Clamp to the auto value rather than honour a setting that cannot work.
            $ceiling !== null && $memoryLimitMib * 1048576 >= $ceiling => $auto,
            default => $memoryLimitMib * 1048576,
        };

        return new self($memory, $timeLimitSec > 0 ? $timeLimitSec : null, $startedAt);
    }

    /** Why the worker should stop now, or null to keep going. */
    public function reasonToStop(int $memoryUsageBytes, float $now): ?string
    {
        if ($this->memoryLimitBytes !== null && $memoryUsageBytes >= $this->memoryLimitBytes) {
            return sprintf(
                'memory %d MiB reached the %d MiB recycle threshold',
                intdiv($memoryUsageBytes, 1048576),
                intdiv($this->memoryLimitBytes, 1048576),
            );
        }

        if ($this->timeLimitSeconds !== null && ($now - $this->startedAt) >= $this->timeLimitSeconds) {
            return sprintf('ran for %ds, reaching the %ds time limit', (int) ($now - $this->startedAt), $this->timeLimitSeconds);
        }

        return null;
    }

    /** Bytes for an ini size value; null when unlimited, zero or unparseable. */
    public static function parseIniBytes(string $value): ?int
    {
        $value = trim($value);

        if (!preg_match('/^(\d+)\s*([kKmMgG]?)$/', $value, $m)) {
            return null;
        }

        $bytes = (int) $m[1] * match (strtolower($m[2])) {
            'k'     => 1024,
            'm'     => 1048576,
            'g'     => 1073741824,
            default => 1,
        };

        return $bytes > 0 ? $bytes : null;
    }
}
