<?php

declare(strict_types=1);

namespace SearchGateway\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SearchGateway\Http\JsonResponse;

final class RateLimitMiddleware implements MiddlewareInterface
{
    public const ATTR_KEY = 'sgw.rateLimitKey';
    public const ATTR_LIMIT = 'sgw.rateLimitLimit';
    public const ATTR_WINDOW = 'sgw.rateLimitWindow';

    /** @var array<string, array{count: int, resetAt: int}> */
    private array $buckets = [];

    public function __construct(
        private readonly int $defaultLimit = 60,
        private readonly int $defaultWindow = 60,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $key = $this->resolveKey($request);
        $limit = $this->resolveLimit($request);
        $window = $this->resolveWindow($request);
        $now = time();

        $bucket = $this->buckets[$key] ?? ['count' => 0, 'resetAt' => $now + $window];
        if ($bucket['resetAt'] <= $now) {
            $bucket = ['count' => 0, 'resetAt' => $now + $window];
        }
        $bucket['count']++;
        $this->buckets[$key] = $bucket;

        $remaining = max(0, $limit - $bucket['count']);
        if ($bucket['count'] > $limit) {
            $response = JsonResponse::create(new \Nyholm\Psr7\Response(), 429, [
                'ok' => false,
                'error' => 'Rate limit exceeded',
                'limit' => $limit,
                'window' => $window,
                'retry_after' => max(1, $bucket['resetAt'] - $now),
            ]);
            return $this->withRateHeaders($response, $limit, $remaining, $bucket['resetAt'] - $now);
        }

        $response = $handler->handle($request);
        return $this->withRateHeaders($response, $limit, $remaining, $bucket['resetAt'] - $now);
    }

    public function reset(?string $key = null): void
    {
        if ($key === null) {
            $this->buckets = [];
            return;
        }
        unset($this->buckets[$key]);
    }

    private function resolveKey(ServerRequestInterface $request): string
    {
        $explicit = $request->getAttribute(self::ATTR_KEY);
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }
        $apiKeyId = $request->getAttribute(AuthMiddleware::ATTR_API_KEY_ID);
        if (is_string($apiKeyId) && $apiKeyId !== '') {
            return 'apikey:' . $apiKeyId;
        }
        $ip = $request->getHeaderLine('X-Forwarded-For');
        if ($ip === '') {
            $server = $request->getServerParams();
            $remote = $server['REMOTE_ADDR'] ?? 'unknown';
            $ip = is_string($remote) ? $remote : 'unknown';
        } else {
            $first = trim(explode(',', $ip)[0] ?? '');
            $ip = $first !== '' ? $first : 'unknown';
        }
        return 'ip:' . $ip;
    }

    private function resolveLimit(ServerRequestInterface $request): int
    {
        $attr = $request->getAttribute(self::ATTR_LIMIT);
        if (is_int($attr) && $attr > 0) {
            return $attr;
        }
        return $this->defaultLimit;
    }

    private function resolveWindow(ServerRequestInterface $request): int
    {
        $attr = $request->getAttribute(self::ATTR_WINDOW);
        if (is_int($attr) && $attr > 0) {
            return $attr;
        }
        return $this->defaultWindow;
    }

    private function withRateHeaders(ResponseInterface $response, int $limit, int $remaining, int $retryAfter): ResponseInterface
    {
        return $response
            ->withHeader('X-RateLimit-Limit', (string) $limit)
            ->withHeader('X-RateLimit-Remaining', (string) $remaining)
            ->withHeader('X-RateLimit-Reset', (string) max(0, $retryAfter));
    }
}
