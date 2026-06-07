<?php

declare(strict_types=1);

namespace SearchGateway\Resilience;

use SearchGateway\Contract\SearchGatewayException;

/**
 * Thrown when a circuit breaker rejects a call.
 * Carries the breaker name for observability and routing decisions.
 */
class CircuitOpenException extends SearchGatewayException
{
    public function __construct(
        string $message,
        private readonly ?string $breakerName = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 503, $previous);
    }

    public function getBreakerName(): ?string
    {
        return $this->breakerName;
    }
}
