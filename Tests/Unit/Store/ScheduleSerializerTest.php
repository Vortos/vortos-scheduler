<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Store;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Schedule\Policy\FireEachMissed;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Trigger\CronDialect;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;
use Vortos\Scheduler\Schedule\Trigger\OneShotTrigger;
use Vortos\Scheduler\Schedule\Trigger\RecurringTrigger;
use Vortos\Scheduler\Store\Dbal\ScheduleSerializer;

final class ScheduleSerializerTest extends TestCase
{
    private ScheduleSerializer $serializer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->serializer = new ScheduleSerializer();
    }

    // ─────────────────────────────────────────────────────────────
    // RecurringTrigger round-trips
    // ─────────────────────────────────────────────────────────────

    public function test_recurring_five_field_round_trip(): void
    {
        $tz      = new DateTimeZone('UTC');
        $trigger = new RecurringTrigger('0 2 * * *', $tz, CronDialect::FiveField);

        [$type, $data] = $this->serializer->serializeTrigger($trigger);
        $restored = $this->serializer->deserializeTrigger($type, $data, $tz);

        self::assertSame('recurring', $type);
        self::assertInstanceOf(RecurringTrigger::class, $restored);
        self::assertSame('0 2 * * *', $restored->expression);
        self::assertSame(CronDialect::FiveField, $restored->dialect);
    }

    public function test_recurring_six_field_round_trip(): void
    {
        $tz      = new DateTimeZone('UTC');
        $trigger = new RecurringTrigger('30 0 2 * * *', $tz, CronDialect::SixFieldSeconds);

        [$type, $data] = $this->serializer->serializeTrigger($trigger);
        $restored = $this->serializer->deserializeTrigger($type, $data, $tz);

        self::assertInstanceOf(RecurringTrigger::class, $restored);
        self::assertSame('30 0 2 * * *', $restored->expression);
        self::assertSame(CronDialect::SixFieldSeconds, $restored->dialect);
    }

    public function test_recurring_dst_aware_timezone_preserved(): void
    {
        $tz      = new DateTimeZone('Australia/Sydney');
        $trigger = new RecurringTrigger('0 2 * * *', $tz, CronDialect::FiveField);

        [$type, $data] = $this->serializer->serializeTrigger($trigger);
        // Timezone is passed at deserialize time (stored in schedule row, not trigger_data)
        $restored = $this->serializer->deserializeTrigger($type, $data, $tz);

        self::assertInstanceOf(RecurringTrigger::class, $restored);
        self::assertSame('Australia/Sydney', $restored->timezone->getName());
    }

    public function test_recurring_new_york_timezone(): void
    {
        $tz      = new DateTimeZone('America/New_York');
        $trigger = new RecurringTrigger('0 9 * * 1-5', $tz);

        [$type, $data] = $this->serializer->serializeTrigger($trigger);
        $restored = $this->serializer->deserializeTrigger($type, $data, $tz);

        self::assertSame('America/New_York', $restored->timezone->getName());
        self::assertSame('0 9 * * 1-5', $restored->expression);
    }

    public function test_recurring_london_timezone(): void
    {
        $tz      = new DateTimeZone('Europe/London');
        $trigger = new RecurringTrigger('0 0 * * *', $tz);

        [$type, $data] = $this->serializer->serializeTrigger($trigger);
        $restored = $this->serializer->deserializeTrigger($type, $data, $tz);

        self::assertSame('Europe/London', $restored->timezone->getName());
    }

    // ─────────────────────────────────────────────────────────────
    // OneShotTrigger round-trips
    // ─────────────────────────────────────────────────────────────

    public function test_oneshot_round_trip(): void
    {
        $fireAt  = new DateTimeImmutable('2026-07-01T05:00:00+00:00');
        $trigger = new OneShotTrigger($fireAt);
        $tz      = new DateTimeZone('UTC');

        [$type, $data] = $this->serializer->serializeTrigger($trigger);
        $restored = $this->serializer->deserializeTrigger($type, $data, $tz);

        self::assertSame('oneshot', $type);
        self::assertInstanceOf(OneShotTrigger::class, $restored);
        self::assertSame(
            $fireAt->setTimezone(new DateTimeZone('UTC'))->getTimestamp(),
            $restored->fireAt->getTimestamp(),
        );
    }

    public function test_oneshot_stores_as_utc_iso8601(): void
    {
        // Fire time in Sydney TZ should be stored as UTC in the JSON
        $fireAt  = new DateTimeImmutable('2026-07-01T05:00:00', new DateTimeZone('Australia/Sydney'));
        $trigger = new OneShotTrigger($fireAt);

        [, $json] = $this->serializer->serializeTrigger($trigger);
        $decoded  = json_decode($json, true);

        // Must end with +00:00 (UTC offset)
        self::assertStringContainsString('+00:00', (string) $decoded['at']);
    }

    // ─────────────────────────────────────────────────────────────
    // IntervalTrigger round-trips
    // ─────────────────────────────────────────────────────────────

    public function test_interval_round_trip(): void
    {
        $trigger = new IntervalTrigger(3600);
        $tz      = new DateTimeZone('UTC');

        [$type, $data] = $this->serializer->serializeTrigger($trigger);
        $restored = $this->serializer->deserializeTrigger($type, $data, $tz);

        self::assertSame('interval', $type);
        self::assertInstanceOf(IntervalTrigger::class, $restored);
        self::assertSame(3600, $restored->intervalSeconds);
    }

    public function test_interval_minimum_1_second_round_trips(): void
    {
        $trigger = new IntervalTrigger(1);

        [$type, $data] = $this->serializer->serializeTrigger($trigger);
        $restored = $this->serializer->deserializeTrigger($type, $data, new DateTimeZone('UTC'));

        self::assertSame(1, $restored->intervalSeconds);
    }

    // ─────────────────────────────────────────────────────────────
    // Trigger: fail-closed on bad input
    // ─────────────────────────────────────────────────────────────

    public function test_unknown_trigger_type_throws_on_deserialize(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unknown trigger type/');

        $json = json_encode(['schema_version' => 1, 'type' => 'quantum', 'x' => 1]);
        $this->serializer->deserializeTrigger('quantum', $json, new DateTimeZone('UTC'));
    }

    public function test_future_schema_version_throws_on_deserialize(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/newer than supported/');

        $json = json_encode(['schema_version' => 999, 'type' => 'interval', 'seconds' => 60]);
        $this->serializer->deserializeTrigger('interval', $json, new DateTimeZone('UTC'));
    }

    public function test_missing_schema_version_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing.*schema_version/');

        $json = json_encode(['type' => 'interval', 'seconds' => 60]);
        $this->serializer->deserializeTrigger('interval', $json, new DateTimeZone('UTC'));
    }

    public function test_invalid_json_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->serializer->deserializeTrigger('interval', 'not-json{', new DateTimeZone('UTC'));
    }

    // ─────────────────────────────────────────────────────────────
    // MisfirePolicy round-trips
    // ─────────────────────────────────────────────────────────────

    public function test_skip_missed_round_trip(): void
    {
        $policy  = MisfirePolicy::skipMissed();
        $json    = $this->serializer->serializeMisfirePolicy($policy);
        $restored = $this->serializer->deserializeMisfirePolicy($json);

        self::assertSame('skip_missed', $restored->key());
    }

    public function test_fire_once_now_round_trip(): void
    {
        $policy  = MisfirePolicy::fireOnceNow();
        $json    = $this->serializer->serializeMisfirePolicy($policy);
        $restored = $this->serializer->deserializeMisfirePolicy($json);

        self::assertSame('fire_once_now', $restored->key());
    }

    public function test_fire_each_missed_with_default_cap_round_trip(): void
    {
        $policy   = MisfirePolicy::fireEachMissed();
        $json     = $this->serializer->serializeMisfirePolicy($policy);
        $restored = $this->serializer->deserializeMisfirePolicy($json);

        self::assertSame('fire_each_missed', $restored->key());
        self::assertInstanceOf(FireEachMissed::class, $restored);
        self::assertSame(FireEachMissed::DEFAULT_CAP, $restored->cap);
    }

    public function test_fire_each_missed_custom_cap_preserved(): void
    {
        $policy   = MisfirePolicy::fireEachMissed(7);
        $json     = $this->serializer->serializeMisfirePolicy($policy);
        $restored = $this->serializer->deserializeMisfirePolicy($json);

        self::assertInstanceOf(FireEachMissed::class, $restored);
        self::assertSame(7, $restored->cap);
    }

    public function test_fire_each_missed_max_cap_preserved(): void
    {
        $policy   = MisfirePolicy::fireEachMissed(FireEachMissed::MAX_CAP);
        $json     = $this->serializer->serializeMisfirePolicy($policy);
        $restored = $this->serializer->deserializeMisfirePolicy($json);

        self::assertInstanceOf(FireEachMissed::class, $restored);
        self::assertSame(FireEachMissed::MAX_CAP, $restored->cap);
    }

    public function test_fire_each_missed_min_cap_preserved(): void
    {
        $policy   = MisfirePolicy::fireEachMissed(FireEachMissed::MIN_CAP);
        $json     = $this->serializer->serializeMisfirePolicy($policy);
        $restored = $this->serializer->deserializeMisfirePolicy($json);

        self::assertInstanceOf(FireEachMissed::class, $restored);
        self::assertSame(FireEachMissed::MIN_CAP, $restored->cap);
    }

    public function test_unknown_misfire_policy_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unknown misfire policy/');

        $json = json_encode(['schema_version' => 1, 'policy' => 'fire_everything_always']);
        $this->serializer->deserializeMisfirePolicy($json);
    }

    // ─────────────────────────────────────────────────────────────
    // Schema version in misfire JSON
    // ─────────────────────────────────────────────────────────────

    public function test_misfire_json_contains_schema_version(): void
    {
        $json    = $this->serializer->serializeMisfirePolicy(MisfirePolicy::skipMissed());
        $decoded = json_decode($json, true);

        self::assertArrayHasKey('schema_version', $decoded);
        self::assertSame(1, $decoded['schema_version']);
    }

    public function test_trigger_json_contains_schema_version(): void
    {
        [, $json]  = $this->serializer->serializeTrigger(new IntervalTrigger(60));
        $decoded   = json_decode($json, true);

        self::assertArrayHasKey('schema_version', $decoded);
        self::assertSame(1, $decoded['schema_version']);
    }
}
