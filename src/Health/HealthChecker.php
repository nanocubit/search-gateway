<?php

declare(strict_types=1);

namespace SearchGateway\Health;

use SearchGateway\Contract\SearchGatewayInterface;

/**
 * Checks provider availability by sending a lightweight probe query.
 */
final class HealthChecker
{
    /**
     * @param array<string, SearchGatewayInterface> $providers
     */
    public function __construct(private array $providers)
    {
    }

    /**
     * @return array<string, array{status: string, latency_ms: float, error: ?string}>
     */
    public function check(string $probeQuery = 'test'): array
    {
        $report = [];
        foreach ($this->providers as $name => $provider) {
            $start = microtime(true);
            try {
                $provider->llmContext($probeQuery, ['docsOnPage' => 1]);
                $report[$name] = [
                    'status' => 'healthy',
                    'latency_ms' => round((microtime(true) - $start) * 1000, 2),
                    'error' => null,
                ];
            } catch (\Throwable $e) {
                $report[$name] = [
                    'status' => 'unhealthy',
                    'latency_ms' => round((microtime(true) - $start) * 1000, 2),
                    'error' => $e->getMessage(),
                ];
            }
        }
        return $report;
    }
}
