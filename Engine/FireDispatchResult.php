<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Engine;

/**
 * Structured outcome of FireDispatcher::dispatch().
 *
 * The daemon (S5) uses this to drive per-result logging and metrics (S8):
 *   Dispatched      → scheduler_fires_total{result="dispatched"}
 *   AlreadyDispatched → idempotent duplicate, no metric needed
 *   SkippedOverlap  → scheduler_fires_total{result="skipped_overlap"}
 *   Deferred        → scheduler_fires_total{result="deferred"} (jitter not yet elapsed)
 */
enum FireDispatchResult
{
    case Dispatched;         // Ledger row inserted + outbox write committed atomically
    case AlreadyDispatched;  // DuplicateSlotException — already dispatched, safe no-op
    case SkippedOverlap;     // Prior run still in-flight (dispatched + within TTL)
    case Deferred;           // Jitter window not yet elapsed — retry next tick
    case CircuitOpen;        // DispatchCircuitBreaker open — skipped to protect the backend
}
