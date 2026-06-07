<?php

declare(strict_types=1);

namespace SearchGateway\Cache;

use SearchGateway\Contract\SearchGatewayInterface;

/**
 * Pre-warms cache for popular queries.
 * Идея из production: прогрев кэша перед пиковой нагрузкой.
 */
final class CacheWarmer
{
    public function __construct(private SearchGatewayInterface $gateway)
    {
    }

    /**
     * @param list<string> $queries
     * @param array<string, mixed> $options
     * @return array<string, array{status: string, latency_ms: float}>
     */
    public function warm(array $queries, array $options = []): array
    {
        $results = [];
        foreach ($queries as $query) {
            $start = microtime(true);
            try {
                $this->gateway->llmContext($query, $options);
                $results[$query] = [
                    'status' => 'warmed',
                    'latency_ms' => round((microtime(true) - $start) * 1000, 2),
                ];
            } catch (\Throwable $e) {
                $results[$query] = [
                    'status' => 'failed',
                    'latency_ms' => round((microtime(true) - $start) * 1000, 2),
                    'error' => $e->getMessage(),
                ];
            }
        }
        return $results;
    }
}
