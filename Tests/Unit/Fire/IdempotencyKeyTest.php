<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Fire;

use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Fire\IdempotencyKey;

final class IdempotencyKeyTest extends TestCase
{
    public function test_from_slot_key_produces_64_hex_chars(): void
    {
        $key = IdempotencyKey::fromSlotKey('some-slot-key');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key->value);
    }

    public function test_deterministic(): void
    {
        $a = IdempotencyKey::fromSlotKey('slot-key');
        $b = IdempotencyKey::fromSlotKey('slot-key');

        self::assertSame($a->value, $b->value);
    }

    public function test_different_inputs_produce_different_keys(): void
    {
        $a = IdempotencyKey::fromSlotKey('slot-a');
        $b = IdempotencyKey::fromSlotKey('slot-b');

        self::assertNotSame($a->value, $b->value);
    }

    public function test_to_string_equals_value(): void
    {
        $key = IdempotencyKey::fromSlotKey('test');

        self::assertSame($key->value, (string) $key);
    }

    public function test_empty_slot_key_produces_known_sha256(): void
    {
        $key = IdempotencyKey::fromSlotKey('');

        // sha256('') is well-known
        self::assertSame(
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            $key->value,
        );
    }

    public function test_value_is_lowercase_hex(): void
    {
        $key = IdempotencyKey::fromSlotKey('any-key');

        self::assertSame(strtolower($key->value), $key->value);
    }

    public function test_key_is_readonly(): void
    {
        $key = IdempotencyKey::fromSlotKey('test');

        $this->expectException(\Error::class);

        // @phpstan-ignore-next-line
        $key->value = 'tampered';
    }
}
