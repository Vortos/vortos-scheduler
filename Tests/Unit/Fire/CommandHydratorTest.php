<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Fire;

use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Fire\CommandHydrator;
use Vortos\Scheduler\Security\Exception\InvalidCommandPayloadException;

final class CommandHydratorTest extends TestCase
{
    private CommandHydrator $hydrator;

    protected function setUp(): void
    {
        $this->hydrator = new CommandHydrator();
    }

    public function test_hydrates_named_constructor_params(): void
    {
        $command = $this->hydrator->hydrate(HydratorFixtureCommand::class, ['name' => 'alice', 'count' => 3]);

        self::assertInstanceOf(HydratorFixtureCommand::class, $command);
        self::assertSame('alice', $command->name);
        self::assertSame(3, $command->count);
    }

    public function test_hydrates_empty_payload_for_no_arg_constructor(): void
    {
        $command = $this->hydrator->hydrate(HydratorNoArgCommand::class, []);

        self::assertInstanceOf(HydratorNoArgCommand::class, $command);
    }

    public function test_missing_required_param_throws(): void
    {
        $this->expectException(InvalidCommandPayloadException::class);
        $this->expectExceptionMessageMatches('/missing required payload key "name"/');

        $this->hydrator->hydrate(HydratorFixtureCommand::class, ['count' => 3]);
    }

    public function test_unknown_payload_key_throws(): void
    {
        $this->expectException(InvalidCommandPayloadException::class);
        $this->expectExceptionMessageMatches('/unknown payload key/');

        $this->hydrator->hydrate(HydratorFixtureCommand::class, ['name' => 'a', 'count' => 1, 'bogus' => 'x']);
    }

    public function test_uses_constructor_default_when_key_absent(): void
    {
        $command = $this->hydrator->hydrate(HydratorDefaultCommand::class, ['name' => 'bob']);

        self::assertSame('bob', $command->name);
        self::assertSame(10, $command->priority);
    }

    public function test_nullable_param_absent_defaults_to_null(): void
    {
        $command = $this->hydrator->hydrate(HydratorNullableCommand::class, []);

        self::assertNull($command->reason);
    }

    public function test_coerces_iso8601_string_to_date_time_immutable(): void
    {
        $command = $this->hydrator->hydrate(HydratorDateCommand::class, ['at' => '2026-07-01T10:00:00+00:00']);

        self::assertInstanceOf(\DateTimeImmutable::class, $command->at);
        self::assertSame('2026-07-01', $command->at->format('Y-m-d'));
    }

    public function test_non_arg_error_from_constructor_is_wrapped(): void
    {
        $this->expectException(InvalidCommandPayloadException::class);

        $this->hydrator->hydrate(HydratorValidatingCommand::class, ['name' => '']);
    }
}

final readonly class HydratorFixtureCommand implements \Vortos\Domain\Command\CommandInterface
{
    public function __construct(public string $name, public int $count) {}

    public function idempotencyKey(): ?string
    {
        return null;
    }
}

final readonly class HydratorNoArgCommand implements \Vortos\Domain\Command\CommandInterface
{
    public function idempotencyKey(): ?string
    {
        return null;
    }
}

final readonly class HydratorDefaultCommand implements \Vortos\Domain\Command\CommandInterface
{
    public function __construct(public string $name, public int $priority = 10) {}

    public function idempotencyKey(): ?string
    {
        return null;
    }
}

final readonly class HydratorNullableCommand implements \Vortos\Domain\Command\CommandInterface
{
    public function __construct(public ?string $reason = null) {}

    public function idempotencyKey(): ?string
    {
        return null;
    }
}

final readonly class HydratorDateCommand implements \Vortos\Domain\Command\CommandInterface
{
    public function __construct(public \DateTimeImmutable $at) {}

    public function idempotencyKey(): ?string
    {
        return null;
    }
}

final readonly class HydratorValidatingCommand implements \Vortos\Domain\Command\CommandInterface
{
    public function __construct(public string $name)
    {
        if ($name === '') {
            throw new \InvalidArgumentException('name cannot be empty');
        }
    }

    public function idempotencyKey(): ?string
    {
        return null;
    }
}
