<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Schedule;

use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Schedule\ScheduleId;

final class ScheduleIdTest extends TestCase
{
    public function test_generate_produces_a_string(): void
    {
        $id = ScheduleId::generate();

        self::assertNotEmpty((string) $id);
    }

    public function test_generate_produces_valid_uuid_format(): void
    {
        $id = ScheduleId::generate();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            (string) $id,
        );
    }

    public function test_two_generated_ids_are_not_equal(): void
    {
        $a = ScheduleId::generate();
        $b = ScheduleId::generate();

        self::assertFalse($a->equals($b));
    }

    public function test_from_string_round_trips(): void
    {
        $id     = ScheduleId::generate();
        $same   = ScheduleId::fromString($id->toString());

        self::assertTrue($id->equals($same));
    }

    public function test_from_string_rejects_invalid_uuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ScheduleId::fromString('not-a-uuid');
    }

    public function test_from_string_rejects_empty_string(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ScheduleId::fromString('');
    }

    public function test_to_string_equals_to_string(): void
    {
        $id = ScheduleId::generate();

        self::assertSame($id->toString(), (string) $id);
    }

    public function test_equals_returns_false_for_different_ids(): void
    {
        $a = ScheduleId::generate();
        $b = ScheduleId::generate();

        self::assertFalse($a->equals($b));
    }

    public function test_equals_returns_true_for_same_value(): void
    {
        $id   = ScheduleId::generate();
        $copy = ScheduleId::fromString($id->toString());

        self::assertTrue($id->equals($copy));
    }
}
