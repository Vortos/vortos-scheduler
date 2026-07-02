<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Conformance;

use Vortos\Scheduler\Store\ScheduleCursorStoreInterface;
use Vortos\Scheduler\Testing\InMemoryScheduleCursorStore;
use Vortos\Scheduler\Testing\ScheduleCursorStoreConformanceTestCase;

/**
 * Runs the cursor-store conformance suite against the pure in-memory driver (no DB required).
 */
final class InMemoryScheduleCursorStoreConformanceTest extends ScheduleCursorStoreConformanceTestCase
{
    protected function createStore(): ScheduleCursorStoreInterface
    {
        return new InMemoryScheduleCursorStore();
    }
}
