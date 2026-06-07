<?php

declare(strict_types=1);

namespace SearchGateway\Plugin;

use SearchGateway\Infrastructure\LoggerInterface;
use SearchGateway\Request\SearchRequest;
use SearchGateway\Request\SearchResponse;

final class LoggingPlugin implements PluginInterface
{
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
        private readonly string $level = 'info',
    ) {
    }

    public function name(): string
    {
        return 'logging';
    }

    public function beforeSearch(SearchRequest $request, PluginContext $context): SearchRequest
    {
        $this->log($context, sprintf(
            '[search-gateway] start route=%s query=%s provider=%s stream=%s',
            $request->routeName,
            $this->shorten($request->query),
            $request->providers[0] ?? 'auto',
            $request->stream ? 'yes' : 'no',
        ));
        return $request;
    }

    public function afterSearch(SearchResponse $response, PluginContext $context): SearchResponse
    {
        $latencyRaw = $response->meta['latency_ms'] ?? 0;
        $latency = is_numeric($latencyRaw) ? (float) $latencyRaw : 0.0;
        $this->log($context, sprintf(
            '[search-gateway] done route=%s status=%d ok=%s latency_ms=%.1f',
            $response->routeName,
            $response->status,
            $response->isOk() ? 'yes' : 'no',
            $latency,
        ));
        return $response;
    }

    private function log(PluginContext $context, string $message): void
    {
        $logger = $context->logger ?? $this->logger;
        if ($logger === null) {
            return;
        }
        $method = $this->level;
        if (method_exists($logger, $method)) {
            $logger->{$method}($message);
            return;
        }
        $logger->info($message);
    }

    private function shorten(string $query): string
    {
        if (strlen($query) <= 80) {
            return $query;
        }
        return substr($query, 0, 77) . '...';
    }
}
