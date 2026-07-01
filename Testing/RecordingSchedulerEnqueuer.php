<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Testing;

use Vortos\Scheduler\Engine\SchedulerEnqueuerPort;
use Vortos\Scheduler\Fire\IdempotencyKey;
use Vortos\Scheduler\Fire\ScheduledFire;
use Vortos\Scheduler\Schedule\Schedule;

/**
 * Test double for SchedulerEnqueuerPort.
 *
 * Records all enqueue() calls without writing to any database. Useful for:
 *   - Unit testing FireDispatcher dispatch logic
 *   - Verifying that exactly the right fires are enqueued
 *   - Verifying that duplicate fires are not enqueued (DuplicateSlotException tested separately)
 *
 * The $shouldThrow flag exercises the FireDispatchException path — pass true
 * to simulate an infrastructure failure inside enqueue().
 */
final class RecordingSchedulerEnqueuer implements SchedulerEnqueuerPort
{
    /** @var list<array{fire: ScheduledFire, schedule: Schedule}> */
    private array $enqueued = [];

    private bool $shouldThrow = false;
    private string $throwMessage = 'Simulated enqueuer failure';

    public function enqueue(ScheduledFire $fire, Schedule $schedule): void
    {
        if ($this->shouldThrow) {
            throw new \RuntimeException($this->throwMessage);
        }

        $this->enqueued[] = ['fire' => $fire, 'schedule' => $schedule];
    }

    public function failOnNext(string $message = 'Simulated enqueuer failure'): void
    {
        $this->shouldThrow   = true;
        $this->throwMessage  = $message;
    }

    public function reset(): void
    {
        $this->enqueued    = [];
        $this->shouldThrow = false;
    }

    /** @return list<ScheduledFire> */
    public function firedSlots(): array
    {
        return array_column($this->enqueued, 'fire');
    }

    public function count(): int
    {
        return count($this->enqueued);
    }

    public function isEmpty(): bool
    {
        return $this->enqueued === [];
    }

    /**
     * Returns true if a fire was enqueued for the given slot key.
     */
    public function hasSlot(string $slot): bool
    {
        foreach ($this->enqueued as $entry) {
            if ($entry['fire']->slot === $slot) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the run_id (sha256 of slot) that would be stored in the ledger
     * for each enqueued fire. Useful for asserting idempotency key values.
     *
     * @return list<string>
     */
    public function runIds(): array
    {
        return array_map(
            fn(ScheduledFire $fire) => IdempotencyKey::fromSlotKey($fire->slot)->value,
            $this->firedSlots(),
        );
    }
}
