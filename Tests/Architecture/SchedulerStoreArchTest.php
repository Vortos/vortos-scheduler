<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Store\Dbal\DbalScheduleRunStore;
use Vortos\Scheduler\Store\Dbal\DbalScheduleStore;
use Vortos\Scheduler\Store\Dbal\ScheduleSerializer;
use Vortos\Scheduler\Store\Exception\DuplicateSlotException;

/**
 * Enforces architectural rules for the S3 store layer.
 *
 * These tests catch dependency-creep: if a rule fires, the new import is almost
 * certainly wrong and should trigger a design conversation.
 */
final class SchedulerStoreArchTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────
    // A: ScheduleSerializer is a pure class (no I/O)
    // ─────────────────────────────────────────────────────────────

    public function test_schedule_serializer_has_no_db_imports(): void
    {
        $src = $this->srcOf(ScheduleSerializer::class);

        self::assertStringNotContainsString(
            'Doctrine\DBAL',
            $src,
            'ScheduleSerializer must not import DBAL — it is a pure value-object converter.',
        );
    }

    public function test_schedule_serializer_has_no_io_calls(): void
    {
        $src = $this->srcOf(ScheduleSerializer::class);

        foreach (['file_get_contents', 'file_put_contents', 'fopen', 'fclose', 'fread', 'fwrite'] as $fn) {
            self::assertStringNotContainsString(
                $fn,
                $src,
                "ScheduleSerializer must not use filesystem I/O ({$fn}).",
            );
        }
    }

    // ─────────────────────────────────────────────────────────────
    // B: Store layer has no Lease/Lock package imports
    // ─────────────────────────────────────────────────────────────

    public function test_dbal_schedule_store_has_no_lease_imports(): void
    {
        $src = $this->srcOf(DbalScheduleStore::class);

        self::assertStringNotContainsString(
            'Vortos\Lock',
            $src,
            'DbalScheduleStore must not import the Lock package.',
        );
        self::assertStringNotContainsString(
            'Vortos\Lease',
            $src,
        );
    }

    public function test_dbal_schedule_run_store_has_no_lease_imports(): void
    {
        $src = $this->srcOf(DbalScheduleRunStore::class);

        self::assertStringNotContainsString(
            'Vortos\Lock',
            $src,
            'DbalScheduleRunStore must not import the Lock package.',
        );
        self::assertStringNotContainsString(
            'Vortos\Lease',
            $src,
        );
    }

    // ─────────────────────────────────────────────────────────────
    // C: Store layer has no Redis/cache imports
    // ─────────────────────────────────────────────────────────────

    public function test_dbal_schedule_store_has_no_redis_imports(): void
    {
        $src = $this->srcOf(DbalScheduleStore::class);

        foreach (['Redis', 'Predis', 'Relay', 'Symfony\Component\Cache', 'illuminate\cache'] as $ns) {
            self::assertStringNotContainsString(
                $ns,
                $src,
                "DbalScheduleStore must not use Redis/cache ({$ns}).",
            );
        }
    }

    // ─────────────────────────────────────────────────────────────
    // D: DuplicateSlotException is a DomainException
    // ─────────────────────────────────────────────────────────────

    public function test_duplicate_slot_exception_is_domain_exception(): void
    {
        self::assertTrue(
            is_subclass_of(DuplicateSlotException::class, \DomainException::class),
            'DuplicateSlotException must extend \DomainException so callers can catch at the domain level.',
        );
    }

    // ─────────────────────────────────────────────────────────────
    // E: insertRun never opens its own transaction
    // ─────────────────────────────────────────────────────────────

    public function test_insert_run_does_not_call_begin_transaction(): void
    {
        $src = $this->srcOf(DbalScheduleRunStore::class);

        // Rough AST-less check: the word 'beginTransaction' must not appear anywhere
        // in the DbalScheduleRunStore source. The caller (FireDispatcher) controls tx.
        self::assertStringNotContainsString(
            'beginTransaction',
            $src,
            'DbalScheduleRunStore::insertRun() must NOT open its own transaction; ' .
            'FireDispatcher owns the transaction boundary.',
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Helper
    // ─────────────────────────────────────────────────────────────

    private function srcOf(string $fqcn): string
    {
        $reflector = new \ReflectionClass($fqcn);
        $path      = $reflector->getFileName();

        self::assertNotFalse($path, "Could not resolve file for {$fqcn}");

        $src = file_get_contents($path);
        self::assertNotFalse($src, "Could not read file at {$path}");

        return $src;
    }
}
