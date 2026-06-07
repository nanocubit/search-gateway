<?php

declare(strict_types=1);

namespace SearchGateway\Contract;

use SearchGateway\Infrastructure\LLMClientInterface;

/**
 * Streaming-capable LLM contract.
 * Extends the base LLMClientInterface with token-level streaming.
 */
interface StreamingLLMClientInterface extends LLMClientInterface
{
    /**
     * Stream the model output. Yields raw text chunks (delta pieces).
     *
     * @param array<string, mixed> $options Provider-specific options (temperature, model, stop, etc.)
     * @return \Generator<int, string, mixed, void>
     */
    public function streamGenerate(string $prompt, array $options = []): \Generator;
}
