<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Lease\Driver;

use DateTimeImmutable;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\Scheduler\Clock\ClockPort;
use Vortos\Scheduler\Lease\Exception\LeaseNotOwnedException;
use Vortos\Scheduler\Lease\Exception\LeaseRenewExpiredException;
use Vortos\Scheduler\Lease\Lease;
use Vortos\Scheduler\Lease\LeasePort;
use Vortos\Scheduler\Lease\LeaseToken;

#[AsDriver('in-memory')]
final class InMemoryLeaseStore implements LeasePort
{
    use LeaseValidation;

    /** @var array<string, array{token: string, expiresAt: DateTimeImmutable}> */
    private array $store = [];

    public function __construct(private readonly ClockPort $clock) {}

    public function acquire(string $key, LeaseToken $token, int $ttlSeconds): ?Lease
    {
        $this->validateKey($key);
        $this->validateTtl($ttlSeconds);

        $now   = $this->clock->now();
        $entry = $this->store[$key] ?? null;

        if ($entry !== null && $entry['expiresAt'] > $now) {
            return null;
        }

        $expiresAt           = $now->modify("+{$ttlSeconds} seconds");
        $this->store[$key]   = ['token' => $token->value, 'expiresAt' => $expiresAt];

        return new Lease($key, $token, $now, $expiresAt);
    }

    public function renew(Lease $lease, int $ttlSeconds): Lease
    {
        $this->validateTtl($ttlSeconds);

        $now   = $this->clock->now();
        $entry = $this->store[$lease->key] ?? null;

        if ($entry === null || $entry['expiresAt'] <= $now) {
            throw new LeaseRenewExpiredException($lease->key);
        }

        if (!hash_equals($entry['token'], $lease->token->value)) {
            throw new LeaseNotOwnedException($lease->key);
        }

        $newExpiry                         = $now->modify("+{$ttlSeconds} seconds");
        $this->store[$lease->key]['expiresAt'] = $newExpiry;

        return $lease->withExtendedExpiry($newExpiry);
    }

    public function release(Lease $lease): void
    {
        $entry = $this->store[$lease->key] ?? null;

        if ($entry === null) {
            return;
        }

        if (!hash_equals($entry['token'], $lease->token->value)) {
            throw new LeaseNotOwnedException($lease->key, 'token mismatch');
        }

        unset($this->store[$lease->key]);
    }
}
