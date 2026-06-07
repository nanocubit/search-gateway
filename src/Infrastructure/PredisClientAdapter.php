<?php

declare(strict_types=1);

namespace SearchGateway\Infrastructure;

use Predis\ClientInterface as PredisClient;
use SearchGateway\Contract\RedisClientInterface;

/**
 * Adapter for the Predis library (predis/predis).
 *
 * @api Requires predis/predis ^2.0
 */
final class PredisClientAdapter implements RedisClientInterface
{
    public function __construct(private readonly PredisClient $redis)
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
            $reply = $this->redis->set($key, $value);
        } else {
            $reply = $this->redis->set($key, $value, 'EX', $ttlSeconds);
        }
        return (string) $reply === 'OK';
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
        return (int) $this->redis->expire($key, $ttlSeconds) === 1;
    }

    public function pExpire(string $key, int $ttlMs): bool
    {
        return (int) $this->redis->pexpire($key, $ttlMs) === 1;
    }

    /**
     * @param list<string> $keys
     * @param list<int|float|string> $args
     */
    public function eval(string $script, array $keys, array $args): mixed
    {
        /** @var list<string|int|float> $merged */
        $merged = array_merge(array_values($keys), array_values($args));
        $stringified = array_map(static fn ($v): string => (string) $v, $merged);
        return $this->redis->eval($script, count($keys), ...$stringified);
    }
}
