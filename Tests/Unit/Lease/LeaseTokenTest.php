<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Lease;

use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Lease\LeaseToken;

final class LeaseTokenTest extends TestCase
{
    public function test_generate_produces_32_char_hex_string(): void
    {
        self::assertSame(32, strlen(LeaseToken::generate()->value));
    }

    public function test_generate_produces_lowercase_hex_only(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', LeaseToken::generate()->value);
    }

    public function test_generate_produces_unique_values(): void
    {
        $values = [];
        for ($i = 0; $i < 100; $i++) {
            $values[] = LeaseToken::generate()->value;
        }

        self::assertCount(100, array_unique($values));
    }

    public function test_from_string_accepts_valid_token(): void
    {
        $value = str_repeat('a', 32);
        $token = LeaseToken::fromString($value);

        self::assertSame($value, $token->value);
    }

    public function test_from_string_rejects_wrong_length_short(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LeaseToken::fromString(str_repeat('a', 31));
    }

    public function test_from_string_rejects_wrong_length_long(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LeaseToken::fromString(str_repeat('a', 33));
    }

    public function test_from_string_rejects_uppercase_hex(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LeaseToken::fromString(str_repeat('A', 32));
    }

    public function test_from_string_rejects_non_hex_chars(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LeaseToken::fromString(str_repeat('g', 32));
    }

    public function test_from_string_rejects_empty_string(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LeaseToken::fromString('');
    }

    public function test_equals_same_value_returns_true(): void
    {
        $value = LeaseToken::generate()->value;
        $a     = LeaseToken::fromString($value);
        $b     = LeaseToken::fromString($value);

        self::assertTrue($a->equals($b));
    }

    public function test_equals_different_value_returns_false(): void
    {
        $a = LeaseToken::generate();
        $b = LeaseToken::generate();

        self::assertFalse($a->equals($b));
    }

    public function test_to_string_returns_value(): void
    {
        $token = LeaseToken::generate();

        self::assertSame($token->value, (string) $token);
    }

    public function test_value_property_accessible(): void
    {
        $token = LeaseToken::generate();

        self::assertIsString($token->value);
        self::assertSame(32, strlen($token->value));
    }
}
