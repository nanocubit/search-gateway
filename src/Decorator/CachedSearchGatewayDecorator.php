<?php

declare(strict_types=1);

namespace SearchGateway\Decorator;

use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Infrastructure\CacheInterface;

/**
 * Perplexity-style caching decorator.
 * Serialises results via JSON for cross-platform cache compatibility.
 */
final class CachedSearchGatewayDecorator implements SearchGatewayInterface
{
    public function __construct(
        private SearchGatewayInterface $inner,
        private CacheInterface $cache,
        private int $ttlSeconds = 3600
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchWeb(string $query, array $options = []): array
    {
        return $this->cached(__FUNCTION__, $query, $options, fn() => $this->inner->searchWeb($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchNews(string $query, array $options = []): array
    {
        return $this->cached(__FUNCTION__, $query, $options, fn() => $this->inner->searchNews($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchImages(string $query, array $options = []): array
    {
        return $this->cached(__FUNCTION__, $query, $options, fn() => $this->inner->searchImages($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function searchGen(string $query, array $options = []): GenerativeSearchResultDTO
    {
        $key = 'search:searchGen:' . md5($query . '|' . json_encode($options, JSON_THROW_ON_ERROR));
        $cached = $this->cache->get($key);
        if (is_string($cached)) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return new GenerativeSearchResultDTO(
                    answer: (string) ($decoded['answer'] ?? ''),
                    sources: is_array($decoded['sources'] ?? null) ? $decoded['sources'] : [],
                    meta: is_array($decoded['meta'] ?? null) ? $decoded['meta'] : []
                );
            }
        }

        $value = $this->inner->searchGen($query, $options);
        $this->cache->set(
            $key,
            json_encode([
                'answer' => $value->answer,
                'sources' => $value->sources,
                'meta' => $value->meta,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $this->ttlSeconds
        );
        return $value;
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>|array<string, mixed>
     */
    public function wordstat(string $query, array $options = []): array
    {
        return $this->cached(__FUNCTION__, $query, $options, fn() => $this->inner->wordstat($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array{url:string, title:string, domain:string, passage:string, score:float}>
     */
    public function llmContext(string $query, array $options = []): array
    {
        return $this->cached(__FUNCTION__, $query, $options, fn() => $this->inner->llmContext($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @template T
     * @param callable(): T $resolver
     * @return T
     */
    private function cached(string $method, string $query, array $options, callable $resolver): mixed
    {
        $key = 'search:' . $method . ':' . md5($query . '|' . json_encode($options, JSON_THROW_ON_ERROR));

        $cached = $this->cache->get($key);
        if (is_string($cached)) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                /** @var T $result */
                $result = $decoded;
                return $result;
            }
        }

        $value = $resolver();

        $this->cache->set(
            $key,
            json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $this->ttlSeconds
        );

        return $value;
    }
}
