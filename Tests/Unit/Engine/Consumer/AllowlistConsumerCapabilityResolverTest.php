<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Engine\Consumer;

use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Engine\Consumer\AllowlistConsumerCapabilityResolver;
use Vortos\Scheduler\Security\CommandSpecValidator;

final class AllowlistConsumerCapabilityResolverTest extends TestCase
{
    public function test_null_validator_means_no_capability_filter(): void
    {
        $resolver = new AllowlistConsumerCapabilityResolver(null);

        self::assertNull($resolver->capableCommandClasses());
    }

    public function test_returns_allowlisted_classes_that_exist(): void
    {
        // One real class + one that does not exist — only the loadable one is a capability.
        $validator = new CommandSpecValidator([
            self::class => true,
            'App\\Not\\Deployed\\Yet' => true,
        ]);

        $resolver = new AllowlistConsumerCapabilityResolver($validator);

        self::assertSame([self::class], $resolver->capableCommandClasses());
    }

    public function test_empty_allowlist_yields_empty_capability_set(): void
    {
        $resolver = new AllowlistConsumerCapabilityResolver(new CommandSpecValidator([]));

        self::assertSame([], $resolver->capableCommandClasses());
    }
}
