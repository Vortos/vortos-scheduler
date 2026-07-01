<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Audit\AuditHashChain;
use Vortos\Scheduler\Audit\SchedulerAuditEntry;

final class SchedulerAuditEntryTest extends TestCase
{
    private function makeEntry(array $overrides = []): SchedulerAuditEntry
    {
        return new SchedulerAuditEntry(
            entryId:     array_key_exists('entryId', $overrides)     ? $overrides['entryId']    : 'test-entry-001',
            sequence:    array_key_exists('sequence', $overrides)    ? $overrides['sequence']   : 0,
            eventType:   array_key_exists('eventType', $overrides)   ? $overrides['eventType']  : 'fire.dispatched',
            actorId:     array_key_exists('actorId', $overrides)     ? $overrides['actorId']    : 'system',
            tenantId:    array_key_exists('tenantId', $overrides)    ? $overrides['tenantId']   : 'tenant-1',
            scheduleId:  array_key_exists('scheduleId', $overrides)  ? $overrides['scheduleId'] : 'sched-abc',
            slot:        array_key_exists('slot', $overrides)        ? $overrides['slot']       : 'sched-abc:2026-07-01T02:00:00+00:00',
            shardIndex:  array_key_exists('shardIndex', $overrides)  ? $overrides['shardIndex'] : null,
            occurredAt:  array_key_exists('occurredAt', $overrides)  ? $overrides['occurredAt'] : '2026-07-01T02:00:05+00:00',
            data:        array_key_exists('data', $overrides)        ? $overrides['data']       : ['lag_ms' => 5000],
            chainKey:    array_key_exists('chainKey', $overrides)    ? $overrides['chainKey']   : 'scheduler:tenant-1:production',
            prevHash:    array_key_exists('prevHash', $overrides)    ? $overrides['prevHash']   : AuditHashChain::GENESIS_HASH,
            contentHash: array_key_exists('contentHash', $overrides) ? $overrides['contentHash'] : str_repeat('a', 64),
            signature:   array_key_exists('signature', $overrides)   ? $overrides['signature']  : str_repeat('b', 64),
        );
    }

    public function test_valid_construction_exposes_all_fields(): void
    {
        $entry = $this->makeEntry();

        self::assertSame('test-entry-001', $entry->entryId);
        self::assertSame(0, $entry->sequence);
        self::assertSame('fire.dispatched', $entry->eventType);
        self::assertSame('system', $entry->actorId);
        self::assertSame('tenant-1', $entry->tenantId);
        self::assertSame('sched-abc', $entry->scheduleId);
        self::assertSame('sched-abc:2026-07-01T02:00:00+00:00', $entry->slot);
        self::assertNull($entry->shardIndex);
        self::assertSame(['lag_ms' => 5000], $entry->data);
        self::assertSame('scheduler:tenant-1:production', $entry->chainKey);
    }

    public function test_negative_sequence_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sequence');

        $this->makeEntry(['sequence' => -1]);
    }

    public function test_empty_entry_id_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('entryId');

        $this->makeEntry(['entryId' => '']);
    }

    public function test_empty_chain_key_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('chainKey');

        $this->makeEntry(['chainKey' => '']);
    }

    public function test_zero_sequence_is_valid(): void
    {
        $entry = $this->makeEntry(['sequence' => 0]);
        self::assertSame(0, $entry->sequence);
    }

    public function test_null_tenant_and_schedule_allowed(): void
    {
        $entry = $this->makeEntry([
            'tenantId'   => null,
            'scheduleId' => null,
            'slot'       => null,
            'shardIndex' => 2,
        ]);

        self::assertNull($entry->tenantId);
        self::assertNull($entry->scheduleId);
        self::assertNull($entry->slot);
        self::assertSame(2, $entry->shardIndex);
    }

    public function test_hashable_fields_excludes_chain_signature_fields(): void
    {
        $entry  = $this->makeEntry();
        $fields = $entry->hashableFields();

        self::assertArrayHasKey('entry_id', $fields);
        self::assertArrayHasKey('event_type', $fields);
        self::assertArrayHasKey('data', $fields);
        self::assertArrayHasKey('chain_key', $fields);

        self::assertArrayNotHasKey('prev_hash', $fields);
        self::assertArrayNotHasKey('content_hash', $fields);
        self::assertArrayNotHasKey('signature', $fields);
    }

    public function test_to_array_round_trip_via_from_array(): void
    {
        $entry   = $this->makeEntry(['shardIndex' => 3, 'tenantId' => null]);
        $rebuilt = SchedulerAuditEntry::fromArray($entry->toArray());

        self::assertSame($entry->entryId, $rebuilt->entryId);
        self::assertSame($entry->sequence, $rebuilt->sequence);
        self::assertSame($entry->eventType, $rebuilt->eventType);
        self::assertSame($entry->actorId, $rebuilt->actorId);
        self::assertNull($rebuilt->tenantId);
        self::assertSame($entry->shardIndex, $rebuilt->shardIndex);
        self::assertSame($entry->data, $rebuilt->data);
        self::assertSame($entry->chainKey, $rebuilt->chainKey);
        self::assertSame($entry->prevHash, $rebuilt->prevHash);
        self::assertSame($entry->contentHash, $rebuilt->contentHash);
        self::assertSame($entry->signature, $rebuilt->signature);
    }

    public function test_from_array_decodes_json_data_string(): void
    {
        $row           = $this->makeEntry()->toArray();
        $row['data']   = json_encode(['lag_ms' => 42], JSON_THROW_ON_ERROR);
        $entry         = SchedulerAuditEntry::fromArray($row);

        self::assertSame(['lag_ms' => 42], $entry->data);
    }

    public function test_hashable_fields_produce_stable_content_hash(): void
    {
        $chain  = new AuditHashChain();
        $entry  = $this->makeEntry(['contentHash' => '']);

        $hash1 = $chain->contentHash($entry->hashableFields(), AuditHashChain::GENESIS_HASH);
        $hash2 = $chain->contentHash($entry->hashableFields(), AuditHashChain::GENESIS_HASH);

        self::assertSame($hash1, $hash2);
        self::assertSame(64, strlen($hash1));
    }
}
