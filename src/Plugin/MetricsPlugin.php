<?php

declare(strict_types=1);

namespace SearchGateway\Plugin;

use SearchGateway\Analytics\SearchAnalytics;
use SearchGateway\Infrastructure\MetricsInterface;
use SearchGateway\Request\SearchRequest;
use SearchGateway\Request\SearchResponse;

final class MetricsPlugin implements PluginInterface
{
    public function __construct(
        private readonly ?MetricsInterface $metrics = null,
        private readonly ?SearchAnalytics $analytics = null,
        private readonly string $metricName = 'search_gateway_requests_total',
        private readonly string $latencyName = 'search_gateway_latency_ms',
    ) {
    }

    public function name(): string
    {
        return 'metrics';
    }

    public function beforeSearch(SearchRequest $request, PluginContext $context): SearchRequest
    {
        return $request;
    }

    public function afterSearch(SearchResponse $response, PluginContext $context): SearchResponse
    {
        $route = $response->routeName;
        $action = $response->action;
        $status = (string) $response->status;
        $latencyRaw = $response->meta['latency_ms'] ?? 0;
        $latency = is_numeric($latencyRaw) ? (float) $latencyRaw : 0.0;
        $latencySeconds = $latency / 1000.0;

        $metrics = $context->metrics ?? $this->metrics;
        $analytics = $context->analytics ?? $this->analytics;

        if ($metrics === null && $analytics === null) {
            return $response;
        }

        if ($metrics !== null) {
            $metrics->increment($this->metricName . '.' . $route . '.' . $status);
            if ($latencySeconds > 0) {
                $metrics->timing($this->latencyName . '.' . $route . '.' . $action, $latencySeconds);
            }
        }

        if ($analytics !== null) {
            $analytics->record([
                'route' => $route,
                'action' => $action,
                'status' => $status,
                'latency_ms' => $latency,
                'ok' => $response->isOk(),
                'apiKeyId' => $this->extractApiKeyId($response->meta),
            ]);
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function extractApiKeyId(array $meta): ?string
    {
        $id = $meta['apiKeyId'] ?? null;
        return is_string($id) ? $id : null;
    }
}
