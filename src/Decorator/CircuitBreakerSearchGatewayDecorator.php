<?php

declare(strict_types=1);

namespace SearchGateway\Decorator;

use SearchGateway\Contract\CircuitBreakerInterface;
use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Contract\SearchGatewayInterface;

/**
 * Wraps any gateway with circuit breaker protection.
 * Accepts any CircuitBreakerInterface (in-memory, Redis, etc.).
 */
final class CircuitBreakerSearchGatewayDecorator implements SearchGatewayInterface
{
    public function __construct(
        private SearchGatewayInterface $inner,
        private CircuitBreakerInterface $breaker
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchWeb(string $query, array $options = []): array
    {
        return $this->breaker->call(fn(): array => $this->inner->searchWeb($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchNews(string $query, array $options = []): array
    {
        return $this->breaker->call(fn(): array => $this->inner->searchNews($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchImages(string $query, array $options = []): array
    {
        return $this->breaker->call(fn(): array => $this->inner->searchImages($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function searchGen(string $query, array $options = []): GenerativeSearchResultDTO
    {
        return $this->breaker->call(fn(): GenerativeSearchResultDTO => $this->inner->searchGen($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int|string, mixed>
     */
    public function wordstat(string $query, array $options = []): array
    {
        return $this->breaker->call(fn(): array => $this->inner->wordstat($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function llmContext(string $query, array $options = []): array
    {
        return $this->breaker->call(fn(): array => $this->inner->llmContext($query, $options));
    }

    public function getBreaker(): CircuitBreakerInterface
    {
        return $this->breaker;
    }
}
