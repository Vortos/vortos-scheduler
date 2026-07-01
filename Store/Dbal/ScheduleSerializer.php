<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Store\Dbal;

use DateTimeImmutable;
use DateTimeZone;
use Vortos\Scheduler\Schedule\Policy\FireEachMissed;
use Vortos\Scheduler\Schedule\Policy\FireOnceNow;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\SkipMissed;
use Vortos\Scheduler\Schedule\Trigger\CronDialect;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;
use Vortos\Scheduler\Schedule\Trigger\OneShotTrigger;
use Vortos\Scheduler\Schedule\Trigger\RecurringTrigger;
use Vortos\Scheduler\Schedule\Trigger\Trigger;

/**
 * Pure serializer — converts Schedule sub-objects to/from DB-storable strings.
 *
 * No DB calls, no I/O. Safe to construct and call in any context including tests
 * without a database connection.
 *
 * All JSON envelopes include "schema_version": 1 so future format changes can be
 * deserialized in a backwards-compatible way. Encountering an unknown schema_version
 * or trigger type throws \RuntimeException (fail-closed — never silently corrupt data).
 */
final class ScheduleSerializer
{
    private const SCHEMA_VERSION = 1;

    // ─────────────────────────────────────────────────────────────
    // Trigger
    // ─────────────────────────────────────────────────────────────

    /**
     * @return array{string, string}  [triggerType, triggerDataJson]
     */
    public function serializeTrigger(Trigger $trigger): array
    {
        if ($trigger instanceof RecurringTrigger) {
            return ['recurring', $this->encode([
                'schema_version' => self::SCHEMA_VERSION,
                'type'           => 'recurring',
                'expression'     => $trigger->expression,
                'dialect'        => $trigger->dialect->value,
            ])];
        }

        if ($trigger instanceof OneShotTrigger) {
            return ['oneshot', $this->encode([
                'schema_version' => self::SCHEMA_VERSION,
                'type'           => 'oneshot',
                'at'             => $trigger->fireAt->setTimezone(new DateTimeZone('UTC'))->format(DateTimeImmutable::ATOM),
            ])];
        }

        if ($trigger instanceof IntervalTrigger) {
            return ['interval', $this->encode([
                'schema_version' => self::SCHEMA_VERSION,
                'type'           => 'interval',
                'seconds'        => $trigger->intervalSeconds,
            ])];
        }

        throw new \RuntimeException(
            'ScheduleSerializer: unknown Trigger class "' . get_class($trigger) . '". ' .
            'Add a case to serializeTrigger() to support this trigger type.',
        );
    }

    /**
     * Reconstructs a Trigger from its stored type + JSON data.
     *
     * $timezone is the schedule-level timezone (stored separately in the schedule row).
     * RecurringTrigger requires it; OneShot and Interval do not.
     */
    public function deserializeTrigger(string $type, string $jsonData, DateTimeZone $timezone): Trigger
    {
        $data = $this->decode($jsonData);

        $this->assertSchemaVersion($data, $jsonData);

        return match ($type) {
            'recurring' => new RecurringTrigger(
                expression: (string) $data['expression'],
                timezone:   $timezone,
                dialect:    CronDialect::from((string) $data['dialect']),
            ),
            'oneshot' => new OneShotTrigger(
                fireAt: new DateTimeImmutable((string) $data['at']),
            ),
            'interval' => new IntervalTrigger(
                intervalSeconds: (int) $data['seconds'],
            ),
            default => throw new \RuntimeException(
                "ScheduleSerializer: unknown trigger type '{$type}' in JSON: {$jsonData}. " .
                'Cannot deserialize — was this written by a newer version of the scheduler?',
            ),
        };
    }

    // ─────────────────────────────────────────────────────────────
    // MisfirePolicy
    // ─────────────────────────────────────────────────────────────

    public function serializeMisfirePolicy(MisfirePolicy $policy): string
    {
        $data = ['schema_version' => self::SCHEMA_VERSION, 'policy' => $policy->key()];

        if ($policy instanceof FireEachMissed) {
            $data['cap'] = $policy->cap;
        }

        return $this->encode($data);
    }

    public function deserializeMisfirePolicy(string $json): MisfirePolicy
    {
        $data = $this->decode($json);

        $this->assertSchemaVersion($data, $json);

        return match ((string) ($data['policy'] ?? '')) {
            'skip_missed'      => MisfirePolicy::skipMissed(),
            'fire_once_now'    => MisfirePolicy::fireOnceNow(),
            'fire_each_missed' => MisfirePolicy::fireEachMissed((int) ($data['cap'] ?? FireEachMissed::DEFAULT_CAP)),
            default            => throw new \RuntimeException(
                "ScheduleSerializer: unknown misfire policy '{$data['policy']}' in JSON: {$json}.",
            ),
        };
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(
                "ScheduleSerializer: failed to decode JSON: {$json}",
                0,
                $e,
            );
        }

        if (!is_array($data)) {
            throw new \RuntimeException(
                "ScheduleSerializer: expected JSON object, got scalar in: {$json}",
            );
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function assertSchemaVersion(array $data, string $originalJson): void
    {
        $version = (int) ($data['schema_version'] ?? 0);

        if ($version === 0) {
            throw new \RuntimeException(
                "ScheduleSerializer: missing 'schema_version' field in JSON: {$originalJson}",
            );
        }

        if ($version > self::SCHEMA_VERSION) {
            throw new \RuntimeException(
                "ScheduleSerializer: schema_version {$version} is newer than supported " .
                'version ' . self::SCHEMA_VERSION . '. Upgrade the scheduler package.',
            );
        }
    }
}
