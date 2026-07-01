<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Lease\Driver;

use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\Scheduler\Clock\ClockPort;
use Vortos\Scheduler\Lease\Exception\LeaseNotOwnedException;
use Vortos\Scheduler\Lease\Exception\LeaseRenewExpiredException;
use Vortos\Scheduler\Lease\Lease;
use Vortos\Scheduler\Lease\LeasePort;
use Vortos\Scheduler\Lease\LeaseToken;

#[AsDriver('redis')]
final class RedisLeaseStore implements LeasePort
{
    use LeaseValidation;

    private const KEY_PREFIX = 'scheduler:lease:';

    private const RENEW_SCRIPT = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    redis.call('PEXPIRE', KEYS[1], ARGV[2])
    return 1
end
local c = redis.call('GET', KEYS[1])
if c == false then
    return 2
end
return 0
LUA;

    private const RELEASE_SCRIPT = <<<'LUA'
local c = redis.call('GET', KEYS[1])
if c == false then
    return 2
end
if c == ARGV[1] then
    redis.call('DEL', KEYS[1])
    return 1
end
return 0
LUA;

    private string $renewSha;
    private string $releaseSha;

    public function __construct(
        private readonly \Redis    $redis,
        private readonly ClockPort $clock,
    ) {
        $this->renewSha   = $this->redis->script('load', self::RENEW_SCRIPT);
        $this->releaseSha = $this->redis->script('load', self::RELEASE_SCRIPT);
    }

    public function acquire(string $key, LeaseToken $token, int $ttlSeconds): ?Lease
    {
        $this->validateKey($key);
        $this->validateTtl($ttlSeconds);

        $ttlMs  = $ttlSeconds * 1000;
        $result = $this->redis->set($this->prefixedKey($key), $token->value, ['NX', 'PX' => $ttlMs]);

        if ($result === false || $result === null) {
            return null;
        }

        $now = $this->clock->now();

        return new Lease($key, $token, $now, $now->modify("+{$ttlSeconds} seconds"));
    }

    public function renew(Lease $lease, int $ttlSeconds): Lease
    {
        $this->validateTtl($ttlSeconds);

        $ttlMs  = $ttlSeconds * 1000;
        $result = $this->evalSha(
            $this->renewSha,
            self::RENEW_SCRIPT,
            [$this->prefixedKey($lease->key)],
            [$lease->token->value, (string) $ttlMs],
        );

        if ($result === 2) {
            throw new LeaseRenewExpiredException($lease->key);
        }

        if ($result !== 1) {
            throw new LeaseNotOwnedException($lease->key);
        }

        $now = $this->clock->now();

        return $lease->withExtendedExpiry($now->modify("+{$ttlSeconds} seconds"));
    }

    public function release(Lease $lease): void
    {
        $result = $this->evalSha(
            $this->releaseSha,
            self::RELEASE_SCRIPT,
            [$this->prefixedKey($lease->key)],
            [$lease->token->value],
        );

        if ($result === 2) {
            return;
        }

        if ($result !== 1) {
            throw new LeaseNotOwnedException($lease->key);
        }
    }

    public function prefixedKey(string $key): string
    {
        return self::KEY_PREFIX . $key;
    }

    /**
     * @param list<string> $keys
     * @param list<string> $args
     */
    private function evalSha(string $sha, string $script, array $keys, array $args): mixed
    {
        try {
            return $this->redis->evalSha($sha, array_merge($keys, $args), count($keys));
        } catch (\RedisException $e) {
            if (str_contains($e->getMessage(), 'NOSCRIPT')) {
                $sha = $this->redis->script('load', $script);

                return $this->redis->evalSha($sha, array_merge($keys, $args), count($keys));
            }

            throw $e;
        }
    }
}
