<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Conformance;

use DateTimeImmutable;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Lease\Driver\InMemoryLeaseStore;
use Vortos\Scheduler\Lease\LeasePort;
use Vortos\Scheduler\Testing\LeasePortConformanceTestCase;

final class InMemoryLeaseStoreConformanceTest extends LeasePortConformanceTestCase
{
    private MutableClock $sharedClock;

    protected function createClock(): MutableClock
    {
        $this->sharedClock = new MutableClock(new DateTimeImmutable('2026-07-01T00:00:00Z'));

        return $this->sharedClock;
    }

    protected function createStore(): LeasePort
    {
        return new InMemoryLeaseStore($this->sharedClock);
    }

    protected function supportsConcurrentAcquire(): bool
    {
        return false;
    }
}
