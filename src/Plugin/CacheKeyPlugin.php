<?php

declare(strict_types=1);

namespace SearchGateway\Plugin;

use SearchGateway\Infrastructure\CacheInterface;
use SearchGateway\Request\SearchRequest;
use SearchGateway\Request\SearchResponse;

final class CacheKeyPlugin implements PluginInterface
{
    public const META_KEY = '_cache_key';

    private ?string $pendingKey = null;

    public function __construct(
        private readonly ?CacheInterface $cache = null,
        private readonly string $prefix = 'sgw:route:',
    ) {
    }

    public function name(): string
    {
        return 'cache-key';
    }

    public function beforeSearch(SearchRequest $request, PluginContext $context): SearchRequest
    {
        $this->pendingKey = $this->buildKey($request);
        return $request->withUserContext(self::META_KEY, $this->pendingKey);
    }

    public function afterSearch(SearchResponse $response, PluginContext $context): SearchResponse
    {
        $key = $this->pendingKey;
        $this->pendingKey = null;
        if ($key === null) {
            return $response;
        }
        $apiKey = $response->meta['apiKeyId'] ?? null;
        if (is_string($apiKey) && $apiKey !== '') {
            return $response;
        }
        $cache = $context->cache ?? $this->cache;
        if ($cache === null || !$response->isOk()) {
            return $response;
        }
        $ttl = $this->ttlFromMeta($response->meta);
        if ($ttl !== null) {
            $cache->set($key, $response->toArray(), $ttl);
        }
        return $response->withMetaValue('cache_key', $key);
    }

    public static function buildKeyFromRequest(SearchRequest $request): string
    {
        $raw = json_encode([
            'q' => $request->query,
            'p' => $request->providers,
            'l' => $request->llm,
            'f' => $request->filters,
            'a' => $request->routeName,
        ], JSON_THROW_ON_ERROR);
        return substr(hash('sha256', $raw), 0, 32);
    }

    private function buildKey(SearchRequest $request): string
    {
        return $this->prefix . $request->routeName . ':' . self::buildKeyFromRequest($request);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function ttlFromMeta(array $meta): ?int
    {
        $ttl = $meta['cache_ttl'] ?? null;
        if (is_int($ttl) && $ttl > 0) {
            return $ttl;
        }
        return null;
    }
}
