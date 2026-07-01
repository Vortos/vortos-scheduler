<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Testing;

use Vortos\Scheduler\Store\RunRetentionOverrideStoreInterface;
use Vortos\Scheduler\Testing\InMemoryRunRetentionOverrideStore;
use Vortos\Scheduler\Testing\RunRetentionOverrideStoreConformanceTestCase;

final class InMemoryRunRetentionOverrideStoreConformanceTest extends RunRetentionOverrideStoreConformanceTestCase
{
    protected function createStore(): RunRetentionOverrideStoreInterface
    {
        return new InMemoryRunRetentionOverrideStore();
    }
}
