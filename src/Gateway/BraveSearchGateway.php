<?php

declare(strict_types=1);

namespace SearchGateway\Gateway;

use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Contract\SearchGatewayException;
use SearchGateway\Infrastructure\HttpClientInterface;

/**
 * Brave Search API gateway.
 * https://api.search.brave.com/res/v1
 */
final class BraveSearchGateway extends AbstractSearchGateway
{
    public function __construct(
        private HttpClientInterface $http,
        private string $apiKey,
        private string $baseUri = 'https://api.search.brave.com/res/v1'
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchWeb(string $query, array $options = []): array
    {
        return $this->search('web/search', $query, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchNews(string $query, array $options = []): array
    {
        return $this->search('news/search', $query, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchImages(string $query, array $options = []): array
    {
        return $this->search('images/search', $query, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array{url:string, title:string, domain:string, passage:string, score:float}>
     */
    public function llmContext(string $query, array $options = []): array
    {
        try {
            $resp = $this->request('llm-context', array_merge(['q' => $query], $options));
            $docs = $this->extractResults($resp);

            return array_values(array_map(
                function (array $doc): array {
                    $passageRaw = $doc['description'] ?? $doc['snippet'] ?? $doc['content'] ?? '';
                    return $this->toLlmChunk([
                        'url' => is_scalar($doc['url'] ?? null) ? (string) $doc['url'] : '',
                        'title' => is_scalar($doc['title'] ?? null) ? (string) $doc['title'] : '',
                        'passage' => is_scalar($passageRaw) ? (string) $passageRaw : '',
                        'score' => is_numeric($doc['score'] ?? 1.0) ? (float) ($doc['score'] ?? 1.0) : 1.0,
                    ]);
                },
                $docs
            ));
        } catch (\Throwable $e) {
            throw new SearchGatewayException($e->getMessage(), (int) $e->getCode(), $e, 'brave');
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    private function search(string $path, string $query, array $options): array
    {
        try {
            $resp = $this->request($path, array_merge(['q' => $query], $options));
            $docs = $this->extractResults($resp);

            $type = match (true) {
                str_starts_with($path, 'images') => 'images',
                str_starts_with($path, 'news') => 'news',
                default => 'web',
            };

            return array_values(array_map(
                static function (array $doc) use ($type): array {
                    $url = is_scalar($doc['url'] ?? null) ? (string) $doc['url'] : '';
                    $title = is_scalar($doc['title'] ?? null) ? (string) $doc['title'] : '';
                    $passageRaw = $doc['description'] ?? $doc['snippet'] ?? '';
                    $passage = is_scalar($passageRaw) ? (string) $passageRaw : '';
                    $score = is_numeric($doc['score'] ?? 1.0) ? (float) ($doc['score'] ?? 1.0) : 1.0;
                    return [
                        'url' => $url,
                        'title' => $title,
                        'passage' => $passage,
                        'score' => $score,
                        'type' => $type,
                    ];
                },
                $docs
            ));
        } catch (\Throwable $e) {
            throw new SearchGatewayException($e->getMessage(), (int) $e->getCode(), $e, 'brave');
        }
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function request(string $path, array $query): array
    {
        $url = rtrim($this->baseUri, '/') . '/' . ltrim($path, '/');
        /** @var array<string, mixed> $resp */
        $resp = $this->http->getJson($url, [
            'headers' => [
                'Accept' => 'application/json',
                'X-Subscription-Token' => $this->apiKey,
            ],
            'query' => $query,
        ]);
        return $resp;
    }

    /**
     * @param array<string, mixed> $resp
     * @return list<array<string, mixed>>
     */
    private function extractResults(array $resp): array
    {
        $raw = $resp['results'] ?? null;
        if (!is_array($raw)) {
            $web = is_array($resp['web'] ?? null) ? $resp['web'] : [];
            $raw = $web['results'] ?? [];
        }
        if (!is_array($raw)) {
            return [];
        }
        /** @var list<array<string, mixed>> $out */
        $out = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }
        return $out;
    }

    public function providerName(): string
    {
        return 'brave';
    }
}
