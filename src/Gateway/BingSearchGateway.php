<?php

declare(strict_types=1);

namespace SearchGateway\Gateway;

use SearchGateway\Contract\SearchGatewayException;
use SearchGateway\Infrastructure\HttpClientInterface;

/**
 * Bing Search API v7.0 gateway.
 */
final class BingSearchGateway extends AbstractSearchGateway
{
    public function __construct(
        private HttpClientInterface $http,
        private string $apiKey,
        private string $baseUri = 'https://api.bing.microsoft.com/v7.0'
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchWeb(string $query, array $options = []): array
    {
        return $this->search('/search', $query, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchNews(string $query, array $options = []): array
    {
        return $this->search('/news/search', $query, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchImages(string $query, array $options = []): array
    {
        return $this->search('/images/search', $query, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array{url:string, title:string, domain:string, passage:string, score:float}>
     */
    public function llmContext(string $query, array $options = []): array
    {
        $docs = $this->searchWeb($query, $options);
        return array_values(array_map(
            fn(array $doc): array => $this->toLlmChunk($doc),
            $docs
        ));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    private function search(string $path, string $query, array $options): array
    {
        try {
            $resp = $this->http->getJson(
                rtrim($this->baseUri, '/') . ltrim($path, '/'),
                [
                    'headers' => ['Ocp-Apim-Subscription-Key' => $this->apiKey],
                    'query' => array_merge(['q' => $query], $options),
                ]
            );

            $key = match ($path) {
                '/images/search' => 'images',
                '/news/search' => 'news',
                default => 'webPages',
            };

            $values = is_array($resp) && is_array($resp[$key] ?? null) ? ($resp[$key]['value'] ?? []) : [];
            /** @var list<array<string, mixed>> $values */
            $values = is_array($values) ? array_values($values) : [];
            $type = match ($path) {
                '/images/search' => 'images',
                '/news/search' => 'news',
                default => 'web',
            };

            return array_values(array_map(
                static function (array $item) use ($type): array {
                    $url = is_scalar($item['url'] ?? null) ? (string) $item['url'] : '';
                    $nameOrTitle = $item['name'] ?? $item['title'] ?? '';
                    $title = is_scalar($nameOrTitle) ? (string) $nameOrTitle : '';
                    $snippetOrDesc = $item['snippet'] ?? $item['description'] ?? '';
                    $passage = is_scalar($snippetOrDesc) ? (string) $snippetOrDesc : '';
                    $rank = $item['rank'] ?? 1.0;
                    return [
                        'url' => $url,
                        'title' => $title,
                        'passage' => $passage,
                        'score' => is_numeric($rank) ? (float) $rank : 1.0,
                        'type' => $type,
                    ];
                },
                $values
            ));
        } catch (\Throwable $e) {
            throw new SearchGatewayException($e->getMessage(), (int) $e->getCode(), $e, 'bing');
        }
    }

    public function providerName(): string
    {
        return 'bing';
    }
}
