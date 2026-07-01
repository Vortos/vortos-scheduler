<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Engine;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Engine\SlotCalculator;
use Vortos\Scheduler\Fire\IdempotencyKey;
use Vortos\Scheduler\Schedule\ScheduleId;

final class SlotCalculatorTest extends TestCase
{
    private SlotCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new SlotCalculator();
    }

    // ──────────────────────────────────────────────
    // Slot key format
    // ──────────────────────────────────────────────

    public function test_slot_key_contains_schedule_id_prefix(): void
    {
        $id  = ScheduleId::generate();
        $key = $this->calc->slotKey($id, new DateTimeImmutable('2026-06-30T02:00:00Z'), new DateTimeZone('UTC'));

        self::assertStringStartsWith($id->toString() . ':', $key);
    }

    public function test_slot_key_contains_iso_8601_timestamp(): void
    {
        $id  = ScheduleId::generate();
        $tz  = new DateTimeZone('UTC');
        $at  = new DateTimeImmutable('2026-06-30T02:00:00Z');
        $key = $this->calc->slotKey($id, $at, $tz);

        // ISO-8601 format with offset (format 'c')
        self::assertMatchesRegularExpression(
            '/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}/',
            $key,
        );
    }

    public function test_slot_key_includes_timezone_offset(): void
    {
        $id    = ScheduleId::generate();
        $at    = new DateTimeImmutable('2026-06-30T16:00:00Z');
        $tz    = new DateTimeZone('Australia/Sydney');

        $key = $this->calc->slotKey($id, $at, $tz);

        // Australia/Sydney in June = AEST (UTC+10)
        self::assertStringContainsString('+10:00', $key);
    }

    // ──────────────────────────────────────────────
    // Determinism
    // ──────────────────────────────────────────────

    public function test_slot_key_is_deterministic(): void
    {
        $id  = ScheduleId::generate();
        $at  = new DateTimeImmutable('2026-06-30T02:00:00Z');
        $tz  = new DateTimeZone('UTC');

        $a = $this->calc->slotKey($id, $at, $tz);
        $b = $this->calc->slotKey($id, $at, $tz);

        self::assertSame($a, $b);
    }

    public function test_slot_key_differs_for_different_schedule_ids(): void
    {
        $idA = ScheduleId::generate();
        $idB = ScheduleId::generate();
        $at  = new DateTimeImmutable('2026-06-30T02:00:00Z');
        $tz  = new DateTimeZone('UTC');

        self::assertNotSame(
            $this->calc->slotKey($idA, $at, $tz),
            $this->calc->slotKey($idB, $at, $tz),
        );
    }

    public function test_slot_key_differs_for_times_one_second_apart(): void
    {
        $id  = ScheduleId::generate();
        $tz  = new DateTimeZone('UTC');
        $t1  = new DateTimeImmutable('2026-06-30T02:00:00Z');
        $t2  = new DateTimeImmutable('2026-06-30T02:00:01Z');

        self::assertNotSame(
            $this->calc->slotKey($id, $t1, $tz),
            $this->calc->slotKey($id, $t2, $tz),
        );
    }

    public function test_slot_key_dst_utc_offset_is_embedded(): void
    {
        $id  = ScheduleId::generate();
        $tz  = new DateTimeZone('Australia/Sydney');

        // Same UTC instant but through different TZ periods (hypothetical offsets for illustration)
        // We verify that two instants with the same local-wall-clock time but different UTC offsets
        // (AEDT +11 vs AEST +10) produce different slot keys.
        $aedtInstant = new DateTimeImmutable('2026-04-04T15:30:00Z'); // 02:30 AEDT (+11)
        $aestInstant = new DateTimeImmutable('2026-04-04T16:30:00Z'); // 02:30 AEST (+10)

        $keyAedt = $this->calc->slotKey($id, $aedtInstant, $tz);
        $keyAest = $this->calc->slotKey($id, $aestInstant, $tz);

        // Both show 02:30 local — but offsets differ → keys differ
        self::assertStringContainsString('02:30', $keyAedt);
        self::assertStringContainsString('02:30', $keyAest);
        self::assertNotSame($keyAedt, $keyAest);
    }

    // ──────────────────────────────────────────────
    // IdempotencyKey
    // ──────────────────────────────────────────────

    public function test_idempotency_key_is_64_hex_chars(): void
    {
        $id  = ScheduleId::generate();
        $key = $this->calc->idempotencyKey($id, new DateTimeImmutable('2026-06-30T02:00:00Z'), new DateTimeZone('UTC'));

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key->value);
    }

    public function test_idempotency_key_is_deterministic(): void
    {
        $id  = ScheduleId::generate();
        $at  = new DateTimeImmutable('2026-06-30T02:00:00Z');
        $tz  = new DateTimeZone('UTC');

        $a = $this->calc->idempotencyKey($id, $at, $tz);
        $b = $this->calc->idempotencyKey($id, $at, $tz);

        self::assertSame($a->value, $b->value);
    }

    public function test_idempotency_key_differs_for_different_schedule_ids(): void
    {
        $idA = ScheduleId::generate();
        $idB = ScheduleId::generate();
        $at  = new DateTimeImmutable('2026-06-30T02:00:00Z');
        $tz  = new DateTimeZone('UTC');

        self::assertNotSame(
            $this->calc->idempotencyKey($idA, $at, $tz)->value,
            $this->calc->idempotencyKey($idB, $at, $tz)->value,
        );
    }

    public function test_idempotency_key_differs_for_different_times(): void
    {
        $id  = ScheduleId::generate();
        $tz  = new DateTimeZone('UTC');

        $k1 = $this->calc->idempotencyKey($id, new DateTimeImmutable('2026-06-30T02:00:00Z'), $tz);
        $k2 = $this->calc->idempotencyKey($id, new DateTimeImmutable('2026-06-30T02:01:00Z'), $tz);

        self::assertNotSame($k1->value, $k2->value);
    }

    public function test_idempotency_key_returns_idempotency_key_instance(): void
    {
        $id  = ScheduleId::generate();
        $key = $this->calc->idempotencyKey($id, new DateTimeImmutable('2026-06-30T02:00:00Z'), new DateTimeZone('UTC'));

        self::assertInstanceOf(IdempotencyKey::class, $key);
    }

    public function test_dst_fall_back_instants_have_different_idempotency_keys(): void
    {
        $id  = ScheduleId::generate();
        $tz  = new DateTimeZone('Australia/Sydney');

        // 02:30 AEDT = UTC 15:30 on Apr 4
        $aedt = new DateTimeImmutable('2026-04-04T15:30:00Z');
        // 02:30 AEST = UTC 16:30 on Apr 4
        $aest = new DateTimeImmutable('2026-04-04T16:30:00Z');

        $kAedt = $this->calc->idempotencyKey($id, $aedt, $tz);
        $kAest = $this->calc->idempotencyKey($id, $aest, $tz);

        self::assertNotSame($kAedt->value, $kAest->value);
    }
}
