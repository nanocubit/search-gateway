<?php

declare(strict_types=1);

namespace SearchGateway\Infrastructure;

/**
 * Abstraction over any LLM provider: OpenAI, YandexGPT, Anthropic, local Ollama, etc.
 */
interface LLMClientInterface
{
    /**
     * Generate text from a prompt.
     *
     * @param array<string, mixed> $options Provider-specific options (temperature, model, stop, etc.)
     */
    public function generate(string $prompt, array $options = []): string;
}
