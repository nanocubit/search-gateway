<?php

declare(strict_types=1);

namespace SearchGateway\Throttling;

/**
 * Adaptive throttling: замедляется при ошибках, ускоряется при успехах.
 * Идея из TCP congestion control / circuit breaker evolution.
 */
final class AdaptiveThrottler
{
    private float $currentDelayMs = 0;
    private int $consecutiveSuccesses = 0;
    private int $consecutiveFailures = 0;

    public function __construct(
        private float $minDelayMs = 0,
        private float $maxDelayMs = 5000,
        private float $backoffMultiplier = 2.0,
        private float $successRecoveryRate = 0.5
    ) {
    }

    public function beforeCall(): void
    {
        if ($this->currentDelayMs > 0) {
            usleep((int) ($this->currentDelayMs * 1000));
        }
    }

    public function onSuccess(): void
    {
        $this->consecutiveSuccesses++;
        $this->consecutiveFailures = 0;
        $this->currentDelayMs = max(
            $this->minDelayMs,
            $this->currentDelayMs * $this->successRecoveryRate
        );
    }

    public function onFailure(): void
    {
        $this->consecutiveFailures++;
        $this->consecutiveSuccesses = 0;
        $this->currentDelayMs = min(
            $this->maxDelayMs,
            max($this->minDelayMs + 100, $this->currentDelayMs * $this->backoffMultiplier)
        );
    }

    public function getCurrentDelayMs(): float
    {
        return $this->currentDelayMs;
    }
}
