<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Engine\CircuitBreaker;

enum CircuitBreakerState
{
    case Closed;   // Normal: requests pass through
    case Open;     // Tripped: requests short-circuit with CircuitOpen result
    case HalfOpen; // Recovery probe: one request allowed through to test backend health
}
