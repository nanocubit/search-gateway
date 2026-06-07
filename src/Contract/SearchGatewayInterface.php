<?php

declare(strict_types=1);

namespace SearchGateway\Contract;

/**
 * Universal search contract inspired by Perplexity, Comet, Brave and Atlas APIs.
 * All gateways MUST implement this interface to be interchangeable inside agents,
 * decorators and multi-provider engines.
 */
interface SearchGatewayInterface
{
    /**
     * Generic web search.
     *
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>> Normalised list of search results.
     */
    public function searchWeb(string $query, array $options = []): array;

    /**
     * News search.
     *
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchNews(string $query, array $options = []): array;

    /**
     * Image search.
     *
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchImages(string $query, array $options = []): array;

    /**
     * Generative / AI search (answer + sources).
     *
     * @param array<string, mixed> $options
     */
    public function searchGen(string $query, array $options = []): GenerativeSearchResultDTO;

    /**
     * Keyword statistics (Yandex Wordstat style).
     *
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>|array<string, mixed>
     */
    public function wordstat(string $query, array $options = []): array;

    /**
     * Brave-like LLM context: clean, ranked passages ready for RAG injection.
     *
     * @param array<string, mixed> $options
     * @return list<array{url:string, title:string, domain:string, passage:string, score:float}>
     */
    public function llmContext(string $query, array $options = []): array;
}
