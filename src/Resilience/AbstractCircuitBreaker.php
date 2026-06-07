<?php

declare(strict_types=1);

namespace SearchGateway\Resilience;

use SearchGateway\Contract\CircuitBreakerInterface;

/**
 * Abstract base for circuit breakers. Provides template-method
 * call() over the state machine primitives (allowRequest/recordSuccess/recordFailure).
 *
 * State machine: CLOSED -> (threshold failures) -> OPEN
 *                OPEN   -> (timeout elapsed)   -> HALF_OPEN (probe)
 *                HALF_OPEN -> (probe success)  -> CLOSED
 *                HALF_OPEN -> (probe failure)  -> OPEN
 */
abstract class AbstractCircuitBreaker implements CircuitBreakerInterface
{
    public const STATE_CLOSED = 'CLOSED';
    public const STATE_OPEN = 'OPEN';
    public const STATE_HALF_OPEN = 'HALF_OPEN';

    public function __construct(
        protected readonly string $name,
        protected readonly int $failureThreshold = 5,
        protected readonly int $recoveryTimeoutSeconds = 30,
        protected readonly int $halfOpenMaxCalls = 3,
    ) {
    }

    /**
     * Convenience wrapper. The state machine is exposed via the interface
     * for composable use (e.g. inside decorators).
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function call(callable $fn): mixed
    {
        $this->allowRequest();
        try {
            $result = $fn();
            $this->recordSuccess();
            return $result;
        } catch (\Throwable $e) {
            $this->recordFailure();
            throw $e;
        }
    }

    public function getName(): string
    {
        return $this->name;
    }
}
