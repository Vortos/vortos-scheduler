<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Fire;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Fire\CommandSpec;

final class CommandSpecTest extends TestCase
{
    public function test_simple_class_name_accepted(): void
    {
        $spec = new CommandSpec('SendEmail');

        self::assertSame('SendEmail', $spec->commandClass);
    }

    public function test_fully_qualified_class_name_accepted(): void
    {
        $spec = new CommandSpec('App\\Command\\SendEmail');

        self::assertSame('App\\Command\\SendEmail', $spec->commandClass);
    }

    public function test_deeply_namespaced_class_accepted(): void
    {
        $spec = new CommandSpec('Vortos\\Scheduler\\Tests\\Fixture\\FakeCommand');

        self::assertSame('Vortos\\Scheduler\\Tests\\Fixture\\FakeCommand', $spec->commandClass);
    }

    public function test_empty_string_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not a valid fully-qualified class name/i');

        new CommandSpec('');
    }

    public function test_class_with_leading_backslash_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // Leading \ is NOT a valid FQCN in PHP's class_exists / autoloader context
        new CommandSpec('\\App\\Command');
    }

    public function test_class_with_spaces_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CommandSpec('App Command');
    }

    public function test_class_with_digits_in_segment_accepted(): void
    {
        $spec = new CommandSpec('App\\V2\\Command');

        self::assertSame('App\\V2\\Command', $spec->commandClass);
    }

    // ──────────────────────────────────────────────
    // Payload validation
    // ──────────────────────────────────────────────

    public function test_empty_payload_accepted(): void
    {
        $spec = new CommandSpec('App\\Command\\Cmd', []);

        self::assertSame([], $spec->payload);
    }

    public function test_scalar_payload_accepted(): void
    {
        $payload = ['key' => 'value', 'num' => 1, 'flag' => true];
        $spec    = new CommandSpec('App\\Cmd', $payload);

        self::assertSame($payload, $spec->payload);
    }

    public function test_nested_array_payload_accepted(): void
    {
        $payload = ['user' => ['id' => 'abc', 'name' => 'Test']];
        $spec    = new CommandSpec('App\\Cmd', $payload);

        self::assertSame($payload, $spec->payload);
    }

    public function test_null_value_in_payload_accepted(): void
    {
        $payload = ['optional' => null];
        $spec    = new CommandSpec('App\\Cmd', $payload);

        self::assertSame($payload, $spec->payload);
    }

    public function test_object_in_payload_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // @phpstan-ignore-next-line
        new CommandSpec('App\\Cmd', ['obj' => new \stdClass()]);
    }

    public function test_nan_float_in_payload_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // @phpstan-ignore-next-line
        new CommandSpec('App\\Cmd', ['value' => NAN]);
    }

    public function test_inf_float_in_payload_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // @phpstan-ignore-next-line
        new CommandSpec('App\\Cmd', ['value' => INF]);
    }

    public function test_command_class_stored_verbatim(): void
    {
        $class = 'My\\Exact\\ClassName';
        $spec  = new CommandSpec($class);

        self::assertSame($class, $spec->commandClass);
    }

    public function test_spec_is_readonly(): void
    {
        $spec = new CommandSpec('App\\Cmd');

        $this->expectException(\Error::class);

        // @phpstan-ignore-next-line
        $spec->commandClass = 'Other\\Cmd';
    }
}
