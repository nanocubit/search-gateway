<?php

declare(strict_types=1);

namespace SearchGateway\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SearchGateway\Analytics\SearchAnalytics;

final class AuditMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ?SearchAnalytics $analytics = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = microtime(true);
        $response = $handler->handle($request);
        $latencyMs = (microtime(true) - $start) * 1000.0;

        if ($this->analytics !== null) {
            $this->analytics->record([
                'kind' => 'http_request',
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'status' => $response->getStatusCode(),
                'latency_ms' => $latencyMs,
                'apiKeyId' => $request->getAttribute(AuthMiddleware::ATTR_API_KEY_ID),
                'ip' => $this->clientIp($request),
                'ua' => $request->getHeaderLine('User-Agent'),
            ]);
        }
        return $response->withHeader('X-Response-Time-ms', sprintf('%.2f', $latencyMs));
    }

    private function clientIp(ServerRequestInterface $request): string
    {
        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0] ?? '');
            if ($first !== '') {
                return $first;
            }
        }
        $server = $request->getServerParams();
        $remote = $server['REMOTE_ADDR'] ?? '';
        return is_string($remote) ? $remote : '';
    }
}
