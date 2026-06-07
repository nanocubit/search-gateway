<?php

declare(strict_types=1);

namespace SearchGateway\Infrastructure;

/**
 * Rate limiter contract (token bucket / leaky bucket).
 * Compatible with Redis, Memcached or in-memory implementations.
 */
interface RateLimiterInterface
{
    /**
     * Acquire a permit. Returns true if allowed, false if rate limited.
     */
    public function acquire(string $key, int $maxRequests, int $windowSeconds): bool;

    /**
     * Time until next permit is available (seconds).
     */
    public function waitTime(string $key, int $maxRequests, int $windowSeconds): float;
}
