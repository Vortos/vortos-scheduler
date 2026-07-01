<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Lease\Driver;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\Scheduler\Clock\ClockPort;
use Vortos\Scheduler\Lease\Exception\LeaseNotOwnedException;
use Vortos\Scheduler\Lease\Exception\LeaseRenewExpiredException;
use Vortos\Scheduler\Lease\Lease;
use Vortos\Scheduler\Lease\LeasePort;
use Vortos\Scheduler\Lease\LeaseToken;

#[AsDriver('sql')]
final class SqlLeaseStore implements LeasePort
{
    use LeaseValidation;

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockPort  $clock,
        private readonly string     $table = 'vortos_scheduler_leases',
    ) {}

    public function acquire(string $key, LeaseToken $token, int $ttlSeconds): ?Lease
    {
        $this->validateKey($key);
        $this->validateTtl($ttlSeconds);

        $now       = $this->clock->now();
        $expiresAt = $now->modify("+{$ttlSeconds} seconds");
        $expiresTs = $expiresAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement(
                "INSERT INTO {$this->table} (lease_key, owner_token, expires_at, acquired_at)
                 VALUES (?, ?, ?, NOW())
                 ON CONFLICT (lease_key) DO UPDATE
                     SET owner_token = EXCLUDED.owner_token,
                         expires_at  = EXCLUDED.expires_at,
                         acquired_at = EXCLUDED.acquired_at,
                         renewed_at  = NULL
                     WHERE {$this->table}.expires_at < NOW()",
                [$key, $token->value, $expiresTs],
            );

            $storedToken = $this->connection->fetchOne(
                "SELECT owner_token FROM {$this->table} WHERE lease_key = ?",
                [$key],
            );

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        if (!is_string($storedToken) || !hash_equals($storedToken, $token->value)) {
            return null;
        }

        return new Lease($key, $token, $now, $expiresAt);
    }

    public function renew(Lease $lease, int $ttlSeconds): Lease
    {
        $this->validateTtl($ttlSeconds);

        $now       = $this->clock->now();
        $expiresAt = $now->modify("+{$ttlSeconds} seconds");
        $expiresTs = $expiresAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $affected = $this->connection->executeStatement(
            "UPDATE {$this->table}
             SET expires_at = ?, renewed_at = NOW()
             WHERE lease_key = ? AND owner_token = ?",
            [$expiresTs, $lease->key, $lease->token->value],
        );

        if ($affected === 0) {
            $storedToken = $this->connection->fetchOne(
                "SELECT owner_token FROM {$this->table} WHERE lease_key = ?",
                [$lease->key],
            );

            if ($storedToken === false || $storedToken === null) {
                throw new LeaseRenewExpiredException($lease->key);
            }

            throw new LeaseNotOwnedException($lease->key);
        }

        return $lease->withExtendedExpiry($expiresAt);
    }

    public function release(Lease $lease): void
    {
        $this->connection->beginTransaction();

        try {
            $storedToken = $this->connection->fetchOne(
                "SELECT owner_token FROM {$this->table} WHERE lease_key = ? FOR UPDATE",
                [$lease->key],
            );

            if ($storedToken === false || $storedToken === null) {
                $this->connection->commit();
                return;
            }

            if (!hash_equals((string) $storedToken, $lease->token->value)) {
                $this->connection->rollBack();
                throw new LeaseNotOwnedException($lease->key);
            }

            $this->connection->executeStatement(
                "DELETE FROM {$this->table} WHERE lease_key = ? AND owner_token = ?",
                [$lease->key, $lease->token->value],
            );

            $this->connection->commit();
        } catch (LeaseNotOwnedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }
}
