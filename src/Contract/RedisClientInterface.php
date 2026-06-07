<?php

declare(strict_types=1);

namespace SearchGateway\Contract;

/**
 * Minimal Redis abstraction used by RedisCircuitBreaker.
 * Compatible with both phpredis (\Redis) and Predis via adapters.
 */
interface RedisClientInterface
{
    /**
     * @return string|null  null when key does not exist.
     */
    public function get(string $key): ?string;

    public function set(string $key, string $value, ?int $ttlSeconds = null): bool;

    public function del(string ...$keys): int;

    public function incr(string $key): int;

    public function expire(string $key, int $ttlSeconds): bool;

    public function pExpire(string $key, int $ttlMs): bool;

    /**
     * Execute a Lua script.
     *
     * @param list<string> $keys
     * @param list<int|float|string> $args
     */
    public function eval(string $script, array $keys, array $args): mixed;
}
