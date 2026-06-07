<?php

declare(strict_types=1);

namespace SearchGateway\Resilience;

/**
 * In-process circuit breaker with Closed / Open / Half-Open states.
 * Suitable for single-worker scenarios (CLI, single SAPI). For multi-process
 * deployments use RedisCircuitBreaker.
 *
 * Not declared final so the legacy {@see CircuitBreaker} class can extend it
 * for backward compatibility.
 */
class InMemoryCircuitBreaker extends AbstractCircuitBreaker
{
    private string $state = self::STATE_CLOSED;
    private int $failureCount = 0;
    private int $halfOpenSuccesses = 0;
    private int $halfOpenProbes = 0;
    private ?float $lastFailureAt = null;

    public function allowRequest(): void
    {
        if ($this->state === self::STATE_OPEN) {
            if ($this->shouldAttemptReset()) {
                $this->transition(self::STATE_HALF_OPEN);
            } else {
                throw new CircuitOpenException(
                    sprintf("Circuit breaker '%s' is OPEN", $this->name),
                    $this->name,
                );
            }
        }

        if ($this->state === self::STATE_HALF_OPEN) {
            if ($this->halfOpenProbes >= $this->halfOpenMaxCalls) {
                throw new CircuitOpenException(
                    sprintf("Circuit breaker '%s' is OPEN (half-open saturated)", $this->name),
                    $this->name,
                );
            }
            $this->halfOpenProbes++;
        }
    }

    public function recordSuccess(): void
    {
        if ($this->state === self::STATE_HALF_OPEN) {
            $this->halfOpenProbes--;
            $this->halfOpenSuccesses++;
            if ($this->halfOpenSuccesses >= $this->halfOpenMaxCalls) {
                $this->transition(self::STATE_CLOSED);
            }
        } else {
            $this->failureCount = 0;
        }
    }

    public function recordFailure(): void
    {
        $this->failureCount++;
        $this->lastFailureAt = microtime(true);

        if ($this->state === self::STATE_HALF_OPEN) {
            $this->transition(self::STATE_OPEN);
        } elseif ($this->failureCount >= $this->failureThreshold) {
            $this->transition(self::STATE_OPEN);
        }
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function reset(): void
    {
        $this->transition(self::STATE_CLOSED);
    }

    private function shouldAttemptReset(): bool
    {
        return $this->lastFailureAt !== null
            && (microtime(true) - $this->lastFailureAt) >= $this->recoveryTimeoutSeconds;
    }

    private function transition(string $newState): void
    {
        $this->state = $newState;
        $this->failureCount = 0;
        $this->halfOpenProbes = 0;
        $this->halfOpenSuccesses = 0;
        if ($newState === self::STATE_CLOSED) {
            $this->lastFailureAt = null;
        }
    }
}
