<?php

declare(strict_types=1);

namespace SearchGateway\Contract;

/**
 * Provider-agnostic circuit breaker contract.
 * Implementations: InMemoryCircuitBreaker, RedisCircuitBreaker.
 */
interface CircuitBreakerInterface
{
    /**
     * Atomically check whether the next call may proceed.
     *
     * @throws \SearchGateway\Resilience\CircuitOpenException when the breaker is OPEN and recovery window has not elapsed.
     */
    public function allowRequest(): void;

    /**
     * Mark a successful call. Resets failure counter and closes the breaker.
     */
    public function recordSuccess(): void;

    /**
     * Mark a failed call. May transition to OPEN.
     */
    public function recordFailure(): void;

    /**
     * Current state snapshot. One of: CLOSED, OPEN, HALF_OPEN.
     */
    public function getState(): string;

    /**
     * Force-reset to CLOSED. Used by admin tooling and tests.
     */
    public function reset(): void;

    /**
     * Template-method wrapper. Executes $fn under the breaker's protection:
     *  - calls {@see allowRequest()} (throws {@see \SearchGateway\Resilience\CircuitOpenException} if OPEN),
     *  - invokes $fn and records success on the happy path,
     *  - records failure and re-throws on any Throwable.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function call(callable $fn): mixed;
}
