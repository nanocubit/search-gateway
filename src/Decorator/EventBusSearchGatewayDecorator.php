<?php

declare(strict_types=1);

namespace SearchGateway\Decorator;

use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Infrastructure\EventBusInterface;

/**
 * Emits lifecycle events for every search operation.
 * Events: search.before, search.after, search.failure
 */
final class EventBusSearchGatewayDecorator implements SearchGatewayInterface
{
    public function __construct(
        private SearchGatewayInterface $inner,
        private EventBusInterface $bus,
        private string $providerName = 'unknown'
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchWeb(string $query, array $options = []): array
    {
        return $this->emit('searchWeb', $query, $options, fn() => $this->inner->searchWeb($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchNews(string $query, array $options = []): array
    {
        return $this->emit('searchNews', $query, $options, fn() => $this->inner->searchNews($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchImages(string $query, array $options = []): array
    {
        return $this->emit('searchImages', $query, $options, fn() => $this->inner->searchImages($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function searchGen(string $query, array $options = []): GenerativeSearchResultDTO
    {
        return $this->emit('searchGen', $query, $options, fn() => $this->inner->searchGen($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>|array<string, mixed>
     */
    public function wordstat(string $query, array $options = []): array
    {
        return $this->emit('wordstat', $query, $options, fn() => $this->inner->wordstat($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array{url:string, title:string, domain:string, passage:string, score:float}>
     */
    public function llmContext(string $query, array $options = []): array
    {
        return $this->emit('llmContext', $query, $options, fn() => $this->inner->llmContext($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function emit(string $method, string $query, array $options, callable $fn): mixed
    {
        $this->bus->emit('search.before', [
            'provider' => $this->providerName,
            'method' => $method,
            'query' => $query,
            'options' => $options,
            'timestamp' => microtime(true),
        ]);

        $start = microtime(true);
        try {
            $result = $fn();
            $this->bus->emit('search.after', [
                'provider' => $this->providerName,
                'method' => $method,
                'query' => $query,
                'elapsed_ms' => round((microtime(true) - $start) * 1000, 2),
                'result_count' => is_array($result) ? count($result) : null,
                'success' => true,
            ]);
            return $result;
        } catch (\Throwable $e) {
            $this->bus->emit('search.failure', [
                'provider' => $this->providerName,
                'method' => $method,
                'query' => $query,
                'elapsed_ms' => round((microtime(true) - $start) * 1000, 2),
                'error' => $e->getMessage(),
                'success' => false,
            ]);
            throw $e;
        }
    }
}
