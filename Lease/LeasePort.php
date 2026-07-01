<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Lease;

use Vortos\Scheduler\Lease\Exception\LeaseNotOwnedException;
use Vortos\Scheduler\Lease\Exception\LeaseRenewExpiredException;

interface LeasePort
{
    public function acquire(string $key, LeaseToken $token, int $ttlSeconds): ?Lease;

    /**
     * @throws LeaseNotOwnedException     if the stored token no longer matches
     * @throws LeaseRenewExpiredException if the lease has already expired
     */
    public function renew(Lease $lease, int $ttlSeconds): Lease;

    /**
     * @throws LeaseNotOwnedException if another owner holds the lease
     */
    public function release(Lease $lease): void;
}
