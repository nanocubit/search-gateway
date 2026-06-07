<?php

declare(strict_types=1);

namespace SearchGateway\Decorator;

use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Infrastructure\MetricsInterface;

/**
 * StatsD / Prometheus metrics decorator.
 */
final class MetricsSearchGatewayDecorator implements SearchGatewayInterface
{
    public function __construct(
        private SearchGatewayInterface $inner,
        private MetricsInterface $metrics
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchWeb(string $query, array $options = []): array
    {
        return $this->measure('search_web', fn() => $this->inner->searchWeb($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchNews(string $query, array $options = []): array
    {
        return $this->measure('search_news', fn() => $this->inner->searchNews($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchImages(string $query, array $options = []): array
    {
        return $this->measure('search_images', fn() => $this->inner->searchImages($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function searchGen(string $query, array $options = []): GenerativeSearchResultDTO
    {
        return $this->measure('search_gen', fn() => $this->inner->searchGen($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>|array<string, mixed>
     */
    public function wordstat(string $query, array $options = []): array
    {
        return $this->measure('wordstat', fn() => $this->inner->wordstat($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array{url:string, title:string, domain:string, passage:string, score:float}>
     */
    public function llmContext(string $query, array $options = []): array
    {
        return $this->measure('llm_context', fn() => $this->inner->llmContext($query, $options));
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function measure(string $name, callable $fn): mixed
    {
        $start = microtime(true);
        try {
            return $fn();
        } finally {
            $elapsed = microtime(true) - $start;
            $this->metrics->timing($name, $elapsed);
            $this->metrics->increment($name . '_count');
        }
    }
}
