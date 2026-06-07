<?php

declare(strict_types=1);

namespace SearchGateway\Resilience;

use SearchGateway\Contract\RedisClientInterface;

/**
 * Distributed atomic circuit breaker backed by Redis.
 *
 * All state transitions happen inside Lua scripts so they are atomic
 * across PHP-FPM workers. The failure counter carries a TTL equal to
 * 2x recoveryTimeoutSeconds to prevent unbounded growth.
 *
 * Storage layout (per breaker):
 *   {prefix}:state            -> 'CLOSED' | 'OPEN' | 'HALF_OPEN'
 *   {prefix}:fails            -> INCR counter, PEXPIRE on every failure
 *   {prefix}:last_fail_ts     -> seconds since epoch
 *   {prefix}:half_open_probes -> INCR counter, EXPIRE on every probe
 */
final class RedisCircuitBreaker extends AbstractCircuitBreaker
{
    private const LUA_ALLOW_REQUEST = <<<'LUA'
        local state = redis.call('GET', KEYS[1]) or 'CLOSED'

        if state == 'CLOSED' then
            return 'CLOSED'
        end

        if state == 'OPEN' then
            local last = tonumber(redis.call('GET', KEYS[3]) or '0')
            if last > 0 and (tonumber(ARGV[1]) - last) >= tonumber(ARGV[2]) then
                redis.call('SET', KEYS[1], 'HALF_OPEN')
                redis.call('SET', KEYS[4], 1, 'EX', tonumber(ARGV[2]))
                return 'HALF_PROBE'
            end
            return 'OPEN'
        end

        if state == 'HALF_OPEN' then
            local probes = tonumber(redis.call('INCR', KEYS[4]))
            redis.call('EXPIRE', KEYS[4], tonumber(ARGV[2]))
            if probes > tonumber(ARGV[3]) then
                return 'OPEN'
            end
            return 'HALF_PROBE'
        end

        return 'CLOSED'
        LUA;

    private const LUA_RECORD_SUCCESS = <<<'LUA'
        redis.call('SET', KEYS[1], 'CLOSED')
        redis.call('DEL', KEYS[2])
        redis.call('DEL', KEYS[3])
        redis.call('DEL', KEYS[4])
        return 'OK'
        LUA;

    private const LUA_RECORD_FAILURE = <<<'LUA'
        local fails = tonumber(redis.call('INCR', KEYS[2]))
        redis.call('PEXPIRE', KEYS[2], tonumber(ARGV[3]))
        redis.call('SET', KEYS[3], ARGV[1])
        redis.call('DEL', KEYS[4])
        if fails >= tonumber(ARGV[2]) then
            redis.call('SET', KEYS[1], 'OPEN')
            return 'OPEN'
        end
        return 'CLOSED'
        LUA;

    private readonly int $failureWindowMs;

    public function __construct(
        private readonly RedisClientInterface $redis,
        string $name,
        int $failureThreshold = 5,
        int $recoveryTimeoutSeconds = 30,
        int $halfOpenMaxCalls = 3,
        private readonly string $keyPrefix = 'search_gateway:cb',
        ?\Closure $clock = null,
    ) {
        parent::__construct($name, $failureThreshold, $recoveryTimeoutSeconds, $halfOpenMaxCalls);
        $this->failureWindowMs = max(1000, $recoveryTimeoutSeconds * 1000 * 2);
        unset($clock);
    }

    public function allowRequest(): void
    {
        $result = $this->redis->eval(
            self::LUA_ALLOW_REQUEST,
            [
                $this->key('state'),
                $this->key('fails'),
                $this->key('last_fail_ts'),
                $this->key('half_open_probes'),
            ],
            [
                time(),
                $this->recoveryTimeoutSeconds,
                $this->halfOpenMaxCalls,
            ],
        );

        if ($result === 'OPEN') {
            throw new CircuitOpenException(
                sprintf("Circuit breaker '%s' is OPEN", $this->name),
                $this->name,
            );
        }
    }

    public function recordSuccess(): void
    {
        $this->redis->eval(
            self::LUA_RECORD_SUCCESS,
            [
                $this->key('state'),
                $this->key('fails'),
                $this->key('last_fail_ts'),
                $this->key('half_open_probes'),
            ],
            [],
        );
    }

    public function recordFailure(): void
    {
        $this->redis->eval(
            self::LUA_RECORD_FAILURE,
            [
                $this->key('state'),
                $this->key('fails'),
                $this->key('last_fail_ts'),
                $this->key('half_open_probes'),
            ],
            [
                time(),
                $this->failureThreshold,
                $this->failureWindowMs,
            ],
        );
    }

    public function getState(): string
    {
        return $this->redis->get($this->key('state')) ?? self::STATE_CLOSED;
    }

    public function reset(): void
    {
        $this->recordSuccess();
    }

    private function key(string $suffix): string
    {
        return sprintf('%s:%s:%s', $this->keyPrefix, $this->name, $suffix);
    }
}
