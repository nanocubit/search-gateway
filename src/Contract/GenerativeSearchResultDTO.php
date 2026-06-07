<?php

declare(strict_types=1);

namespace SearchGateway\Contract;

/**
 * Immutable DTO for generative search responses.
 * Mirrors Perplexity / Comet answer shape.
 */
final readonly class GenerativeSearchResultDTO
{
    /**
     * @param string $answer Generated answer text (may be empty if provider only returns sources).
     * @param list<array<string, mixed>> $sources Normalised source documents.
     * @param array<string, mixed> $meta Provider metadata, latency, tokens, etc.
     */
    public function __construct(
        public string $answer,
        public array $sources = [],
        public array $meta = [],
    ) {
    }

    /**
     * Factory for empty result (useful in fallback scenarios).
     */
    public static function empty(string $provider = 'unknown'): self
    {
        return new self(answer: '', sources: [], meta: ['provider' => $provider]);
    }
}
