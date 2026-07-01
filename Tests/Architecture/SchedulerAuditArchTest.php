<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Audit\Dbal\DbalSchedulerAuditRepository;
use Vortos\Scheduler\Audit\SchedulerAuditProjector;
use Vortos\Scheduler\Audit\SchedulerAuditRepositoryInterface;

/**
 * Architecture guardrails for the S8 audit ledger layer.
 */
final class SchedulerAuditArchTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────
    // A: Append-only — no UPDATE or DELETE in the DBAL driver
    // ─────────────────────────────────────────────────────────────

    public function test_dbal_audit_repository_has_no_update_statements(): void
    {
        $src = $this->srcOf(DbalSchedulerAuditRepository::class);

        self::assertStringNotContainsString(
            'executeStatement(\'UPDATE',
            $src,
        );
        self::assertStringNotContainsString(
            "executeStatement('UPDATE",
            $src,
        );
        // Check for QueryBuilder update path too
        self::assertStringNotContainsString(
            '->update(',
            $src,
            'DbalSchedulerAuditRepository must not execute any UPDATE — the ledger is append-only.',
        );
    }

    public function test_dbal_audit_repository_has_no_delete_statements(): void
    {
        $src = $this->srcOf(DbalSchedulerAuditRepository::class);

        self::assertStringNotContainsString(
            "executeStatement('DELETE",
            $src,
        );
        self::assertStringNotContainsString(
            '->delete(',
            $src,
            'DbalSchedulerAuditRepository must not execute any DELETE — the ledger is append-only.',
        );
    }

    // ─────────────────────────────────────────────────────────────
    // B: Interface contract — no delete/update methods in port
    // ─────────────────────────────────────────────────────────────

    public function test_audit_repository_interface_has_no_delete_method(): void
    {
        $reflection = new \ReflectionClass(SchedulerAuditRepositoryInterface::class);
        $methods    = array_map(fn (\ReflectionMethod $m) => $m->getName(), $reflection->getMethods());

        self::assertNotContains(
            'delete',
            $methods,
            'SchedulerAuditRepositoryInterface must not define a delete() method.',
        );
        self::assertNotContains(
            'deleteEntry',
            $methods,
        );
        self::assertNotContains(
            'truncate',
            $methods,
        );
    }

    public function test_audit_repository_interface_has_no_update_method(): void
    {
        $reflection = new \ReflectionClass(SchedulerAuditRepositoryInterface::class);
        $methods    = array_map(fn (\ReflectionMethod $m) => $m->getName(), $reflection->getMethods());

        self::assertNotContains('update', $methods);
        self::assertNotContains('updateEntry', $methods);
        self::assertNotContains('modify', $methods);
    }

    // ─────────────────────────────────────────────────────────────
    // C: SchedulerAuditProjector does not import Redis/cache
    // ─────────────────────────────────────────────────────────────

    public function test_projector_has_no_redis_imports(): void
    {
        $src = $this->srcOf(SchedulerAuditProjector::class);

        foreach (['Redis', 'Predis', 'Relay', 'Symfony\Component\Cache'] as $ns) {
            self::assertStringNotContainsString(
                $ns,
                $src,
                "SchedulerAuditProjector must not import cache or Redis ({$ns}).",
            );
        }
    }

    // ─────────────────────────────────────────────────────────────
    // D: HMAC key must never be written to data payload
    // ─────────────────────────────────────────────────────────────

    public function test_projector_does_not_embed_hmac_key_in_data(): void
    {
        $src = $this->srcOf(SchedulerAuditProjector::class);

        // The key is stored as $this->hmacKey; it should only pass to chain->sign() / chain->signingMessage()
        // and NEVER be put into the $data array that goes into the ledger payload.
        self::assertStringNotContainsString(
            "'hmac_key'",
            $src,
            'HMAC key must never appear as a data array key in the audit payload.',
        );
        self::assertStringNotContainsString(
            '"hmac_key"',
            $src,
        );
    }

    // ─────────────────────────────────────────────────────────────
    // E: DBAL driver does not open nested transactions
    // ─────────────────────────────────────────────────────────────

    public function test_dbal_audit_repository_uses_transactional_not_begin(): void
    {
        $src = $this->srcOf(DbalSchedulerAuditRepository::class);

        self::assertStringContainsString(
            'transactional',
            $src,
            'DbalSchedulerAuditRepository must use Connection::transactional() for atomic appends.',
        );
        self::assertStringNotContainsString(
            'beginTransaction',
            $src,
            'DbalSchedulerAuditRepository must not call beginTransaction() directly.',
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
