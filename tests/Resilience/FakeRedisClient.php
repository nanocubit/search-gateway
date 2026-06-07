<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Resilience;

use SearchGateway\Contract\RedisClientInterface;

/**
 * In-memory RedisClientInterface for tests.
 * Implements the subset of operations used by RedisCircuitBreaker.
 * Lua scripts are detected by string prefix and executed in PHP.
 */
final class FakeRedisClient implements RedisClientInterface
{
    /** @var array<string, array{value:string, expiresAt:float|null}> */
    private array $store = [];

    /** @var list<array{script:string, keys:list<string>, args:list<int|float|string>}> */
    public array $evalCalls = [];

    public function get(string $key): ?string
    {
        $entry = $this->store[$key] ?? null;
        if ($entry === null) {
            return null;
        }
        if ($entry['expiresAt'] !== null && $entry['expiresAt'] <= microtime(true)) {
            unset($this->store[$key]);
            return null;
        }
        return $entry['value'];
    }

    public function set(string $key, string $value, ?int $ttlSeconds = null): bool
    {
        $expiresAt = $ttlSeconds !== null ? microtime(true) + $ttlSeconds : null;
        $this->store[$key] = ['value' => $value, 'expiresAt' => $expiresAt];
        return true;
    }

    public function del(string ...$keys): int
    {
        $count = 0;
        foreach ($keys as $key) {
            if (isset($this->store[$key])) {
                unset($this->store[$key]);
                $count++;
            }
        }
        return $count;
    }

    public function incr(string $key): int
    {
        $current = (int) ($this->get($key) ?? '0');
        $new = $current + 1;
        $this->set($key, (string) $new);
        return $new;
    }

    public function expire(string $key, int $ttlSeconds): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return false;
        }
        $this->store[$key] = ['value' => $value, 'expiresAt' => microtime(true) + $ttlSeconds];
        return true;
    }

    public function pExpire(string $key, int $ttlMs): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return false;
        }
        $this->store[$key] = ['value' => $value, 'expiresAt' => microtime(true) + ($ttlMs / 1000)];
        return true;
    }

    public function eval(string $script, array $keys, array $args): mixed
    {
        $this->evalCalls[] = ['script' => $script, 'keys' => $keys, 'args' => $args];
        [$stateKey, $failsKey, $lastKey, $probesKey] = $keys;
        [$arg1, $arg2, $arg3, $arg4] = array_pad($args, 4, 0);

        if (
            str_contains($script, "redis.call('SET', KEYS[1], 'CLOSED')")
            && str_contains($script, "redis.call('DEL', KEYS[2])")
        ) {
            $this->set($stateKey, 'CLOSED');
            $this->del($failsKey, $lastKey, $probesKey);
            return 'OK';
        }

        if (
            str_contains($script, "redis.call('SET', KEYS[1], 'OPEN')")
            && str_contains($script, "redis.call('PEXPIRE'")
        ) {
            $this->incr($failsKey);
            $this->pExpire($failsKey, (int) $arg3);
            $this->set($lastKey, (string) $arg1);
            $this->del($probesKey);
            $fails = (int) ($this->get($failsKey) ?? '0');
            if ($fails >= (int) $arg2) {
                $this->set($stateKey, 'OPEN');
                return 'OPEN';
            }
            return 'CLOSED';
        }

        $state = $this->get($stateKey) ?? 'CLOSED';
        $recovery = (int) $arg2;
        $halfOpenMax = (int) $arg3;
        $now = (int) $arg1;

        if ($state === 'CLOSED') {
            return 'CLOSED';
        }
        if ($state === 'OPEN') {
            $last = (float) ($this->get($lastKey) ?? '0');
            if ($last > 0 && ((int) $arg1 - (int) $last) >= $recovery) {
                $this->set($stateKey, 'HALF_OPEN');
                $this->set($probesKey, '1');
                $this->expire($probesKey, $recovery);
                return 'HALF_PROBE';
            }
            return 'OPEN';
        }
        if ($state === 'HALF_OPEN') {
            $probes = (int) ($this->get($probesKey) ?? '0') + 1;
            $this->set($probesKey, (string) $probes);
            $this->expire($probesKey, $recovery);
            if ($probes > $halfOpenMax) {
                return 'OPEN';
            }
            return 'HALF_PROBE';
        }

        return 'CLOSED';
    }
}
