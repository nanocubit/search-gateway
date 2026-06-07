<?php

declare(strict_types=1);

namespace SearchGateway\Contract;

use RuntimeException;

/**
 * Unified exception for all search gateway failures.
 * Decorators can catch this to implement fallback / retry logic.
 */
class SearchGatewayException extends RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        private ?string $provider = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }
}
