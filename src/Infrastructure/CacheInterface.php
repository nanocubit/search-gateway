<?php

declare(strict_types=1);

namespace SearchGateway\Infrastructure;

/**
 * Minimal cache contract. Compatible with PSR-16, Redis, Memcached.
 */
interface CacheInterface
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value, int $ttlSeconds): bool;
}
