<?php

declare(strict_types=1);

namespace SearchGateway\Gateway;

use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Contract\SearchGatewayException;
use SearchGateway\Infrastructure\HttpClientInterface;

/**
 * Perplexity Search API gateway (Sonar / Sonar Pro).
 * https://docs.perplexity.ai/reference/post_chat_completions
 */
final class PerplexitySearchGateway extends AbstractSearchGateway
{
    public function __construct(
        private HttpClientInterface $http,
        private string $apiKey,
        private string $model = 'sonar',
        private string $baseUri = 'https://api.perplexity.ai'
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchWeb(string $query, array $options = []): array
    {
        $gen = $this->searchGen($query, $options);
        return $gen->sources;
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchNews(string $query, array $options = []): array
    {
        $options['search_recency_filter'] = 'day';
        return $this->searchWeb($query, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchImages(string $query, array $options = []): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $options
     */
    public function searchGen(string $query, array $options = []): GenerativeSearchResultDTO
    {
        try {
            $payload = array_merge([
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => 'Be precise and concise.'],
                    ['role' => 'user', 'content' => $query],
                ],
                'return_citations' => true,
                'return_images' => false,
            ], $options);

            /** @var array<string, mixed> $resp */
            $resp = $this->http->postJson(
                rtrim($this->baseUri, '/') . '/chat/completions',
                $payload,
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type' => 'application/json',
                    ],
                ]
            );

            $choice = is_array($resp['choices'] ?? null) ? ($resp['choices'][0]['message'] ?? []) : [];
            $answer = is_scalar($choice['content'] ?? null) ? (string) $choice['content'] : '';
            $citationsRaw = $resp['citations'] ?? [];
            $citations = is_array($citationsRaw) ? array_values(array_filter($citationsRaw, 'is_string')) : [];

            $sources = array_map(
                static fn(string $url, int $idx): array => [
                    'url' => $url,
                    'title' => 'Source ' . ($idx + 1),
                    'passage' => '',
                    'score' => 1.0,
                    'type' => 'web',
                ],
                $citations,
                array_keys($citations)
            );

            return new GenerativeSearchResultDTO(
                answer: $answer,
                sources: $sources,
                meta: [
                    'provider' => 'perplexity',
                    'model' => $this->model,
                    'usage' => is_array($resp['usage'] ?? null) ? $resp['usage'] : [],
                ]
            );
        } catch (\Throwable $e) {
            throw new SearchGatewayException($e->getMessage(), (int) $e->getCode(), $e, 'perplexity');
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array{url:string, title:string, domain:string, passage:string, score:float}>
     */
    public function llmContext(string $query, array $options = []): array
    {
        $gen = $this->searchGen($query, $options);
        return array_values(array_map(
            fn(array $doc): array => $this->toLlmChunk($doc),
            $gen->sources
        ));
    }

    public function providerName(): string
    {
        return 'perplexity';
    }
}
