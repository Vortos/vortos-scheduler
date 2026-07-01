<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Lease\Driver;

use Doctrine\DBAL\Connection;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\Scheduler\Clock\ClockPort;
use Vortos\Scheduler\Lease\Exception\LeaseNotOwnedException;
use Vortos\Scheduler\Lease\Exception\LeaseRenewExpiredException;
use Vortos\Scheduler\Lease\Lease;
use Vortos\Scheduler\Lease\LeasePort;
use Vortos\Scheduler\Lease\LeaseToken;

#[AsDriver('postgres-advisory')]
final class PostgresAdvisoryLeaseStore implements LeasePort
{
    use LeaseValidation;

    /** @var array<string, array{hash: int, lease: Lease}> */
    private array $held = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockPort  $clock,
    ) {}

    public function acquire(string $key, LeaseToken $token, int $ttlSeconds): ?Lease
    {
        $this->validateKey($key);
        $this->validateTtl($ttlSeconds);

        $now  = $this->clock->now();
        $hash = $this->hashKey($key);

        if (isset($this->held[$key])) {
            $existing = $this->held[$key]['lease'];

            if (!$existing->isExpired($now)) {
                return null;
            }

            $this->connection->fetchOne('SELECT pg_advisory_unlock(?)', [$hash]);
            unset($this->held[$key]);
        }

        $acquired = (bool) $this->connection->fetchOne('SELECT pg_try_advisory_lock(?)', [$hash]);

        if (!$acquired) {
            return null;
        }

        $expiresAt      = $now->modify("+{$ttlSeconds} seconds");
        $lease          = new Lease($key, $token, $now, $expiresAt);
        $this->held[$key] = ['hash' => $hash, 'lease' => $lease];

        return $lease;
    }

    public function renew(Lease $lease, int $ttlSeconds): Lease
    {
        $this->validateTtl($ttlSeconds);

        $now = $this->clock->now();

        if (!isset($this->held[$lease->key])) {
            throw new LeaseRenewExpiredException($lease->key);
        }

        $current = $this->held[$lease->key]['lease'];

        if ($current->isExpired($now)) {
            throw new LeaseRenewExpiredException($lease->key);
        }

        if (!$current->isOwnedBy($lease->token)) {
            throw new LeaseNotOwnedException($lease->key);
        }

        $newExpiry = $now->modify("+{$ttlSeconds} seconds");
        $newLease  = $lease->withExtendedExpiry($newExpiry);

        $this->held[$lease->key]['lease'] = $newLease;

        return $newLease;
    }

    public function release(Lease $lease): void
    {
        if (!isset($this->held[$lease->key])) {
            return;
        }

        $current = $this->held[$lease->key]['lease'];

        if (!$current->isOwnedBy($lease->token)) {
            throw new LeaseNotOwnedException($lease->key);
        }

        $hash = $this->held[$lease->key]['hash'];
        $this->connection->fetchOne('SELECT pg_advisory_unlock(?)', [$hash]);
        unset($this->held[$lease->key]);
    }

    public function releaseAll(): void
    {
        $this->connection->fetchOne('SELECT pg_advisory_unlock_all()');
        $this->held = [];
    }

    private function hashKey(string $key): int
    {
        $raw      = hash('sha256', 'scheduler:' . $key, true);
        $unsigned = unpack('J', substr($raw, 0, 8))[1];

        if ($unsigned > PHP_INT_MAX) {
            return (int) ($unsigned - (2 ** 64));
        }

        return (int) $unsigned;
    }
}
