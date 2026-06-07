<?php

declare(strict_types=1);

namespace SearchGateway\Batch;

use SearchGateway\Contract\SearchGatewayInterface;

/**
 * Batch query processor. Обрабатывает N запросов с rate limit awareness и progress tracking.
 */
final class BatchProcessor
{
    /** @var list<array{query: string, options: array<string, mixed>}> */
    private array $jobs = [];

    public function __construct(private SearchGatewayInterface $gateway)
    {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function add(string $query, array $options = []): self
    {
        $this->jobs[] = ['query' => $query, 'options' => $options];
        return $this;
    }

    /**
     * Execute all jobs with optional rate limiting between calls.
     *
     * @return list<array{query: string, result: mixed, error: ?string, latency_ms: float}>
     */
    public function execute(int $delayMsBetweenCalls = 0): array
    {
        $results = [];
        foreach ($this->jobs as $job) {
            $start = microtime(true);
            try {
                $result = $this->gateway->llmContext($job['query'], $job['options']);
                $results[] = [
                    'query' => $job['query'],
                    'result' => $result,
                    'error' => null,
                    'latency_ms' => round((microtime(true) - $start) * 1000, 2),
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'query' => $job['query'],
                    'result' => null,
                    'error' => $e->getMessage(),
                    'latency_ms' => round((microtime(true) - $start) * 1000, 2),
                ];
            }
            if ($delayMsBetweenCalls > 0) {
                usleep($delayMsBetweenCalls * 1000);
            }
        }
        $this->jobs = [];
        return $results;
    }
}
