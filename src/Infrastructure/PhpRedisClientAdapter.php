<?php

declare(strict_types=1);

namespace SearchGateway\Infrastructure;

use SearchGateway\Contract\RedisClientInterface;

/**
 * Adapter for the phpredis extension (\Redis).
 *
 * @api Requires ext-redis.
 */
final class PhpRedisClientAdapter implements RedisClientInterface
{
    public function __construct(private readonly \Redis $redis)
    {
    }

    public function get(string $key): ?string
    {
        $value = $this->redis->get($key);
        return is_string($value) ? $value : null;
    }

    public function set(string $key, string $value, ?int $ttlSeconds = null): bool
    {
        if ($ttlSeconds === null) {
            return (bool) $this->redis->set($key, $value);
        }
        return (bool) $this->redis->set($key, $value, ['EX' => $ttlSeconds]);
    }

    public function del(string ...$keys): int
    {
        if ($keys === []) {
            return 0;
        }
        return (int) $this->redis->del($keys);
    }

    public function incr(string $key): int
    {
        return (int) $this->redis->incr($key);
    }

    public function expire(string $key, int $ttlSeconds): bool
    {
        return (bool) $this->redis->expire($key, $ttlSeconds);
    }

    public function pExpire(string $key, int $ttlMs): bool
    {
        return (bool) $this->redis->pExpire($key, $ttlMs);
    }

    /**
     * @param list<string> $keys
     * @param list<int|float|string> $args
     */
    public function eval(string $script, array $keys, array $args): mixed
    {
        $flat = array_merge(array_values($args), array_values($keys));
        return $this->redis->eval($script, $flat, count($keys));
    }
}
