<?php

declare(strict_types=1);

namespace SearchGateway\Decorator;

use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Infrastructure\LoggerInterface;

/**
 * PSR-3 logging decorator for observability and audit.
 */
final class LoggerSearchGatewayDecorator implements SearchGatewayInterface
{
    public function __construct(
        private SearchGatewayInterface $inner,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchWeb(string $query, array $options = []): array
    {
        return $this->log('searchWeb', $query, $options, fn() => $this->inner->searchWeb($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchNews(string $query, array $options = []): array
    {
        return $this->log('searchNews', $query, $options, fn() => $this->inner->searchNews($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchImages(string $query, array $options = []): array
    {
        return $this->log('searchImages', $query, $options, fn() => $this->inner->searchImages($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function searchGen(string $query, array $options = []): GenerativeSearchResultDTO
    {
        return $this->log('searchGen', $query, $options, fn() => $this->inner->searchGen($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>|array<string, mixed>
     */
    public function wordstat(string $query, array $options = []): array
    {
        return $this->log('wordstat', $query, $options, fn() => $this->inner->wordstat($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array{url:string, title:string, domain:string, passage:string, score:float}>
     */
    public function llmContext(string $query, array $options = []): array
    {
        return $this->log('llmContext', $query, $options, fn() => $this->inner->llmContext($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function log(string $method, string $query, array $options, callable $fn): mixed
    {
        $this->logger->debug('SearchGateway::' . $method, ['query' => $query, 'options' => $options]);
        $start = microtime(true);
        try {
            $result = $fn();
            $this->logger->info('SearchGateway::' . $method . ' success', [
                'query' => $query,
                'elapsed_ms' => round((microtime(true) - $start) * 1000, 2),
                'result_count' => is_array($result) ? count($result) : null,
            ]);
            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('SearchGateway::' . $method . ' failure', [
                'query' => $query,
                'error' => $e->getMessage(),
                'elapsed_ms' => round((microtime(true) - $start) * 1000, 2),
            ]);
            throw $e;
        }
    }
}
