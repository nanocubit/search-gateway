<?php

declare(strict_types=1);

namespace SearchGateway\Gateway;

use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Contract\SearchGatewayException;
use SearchGateway\Infrastructure\NormalizerTrait;

/**
 * Yandex Search API gateway.
 * Expects an injected client object compatible with tigusigalpa/yandex-search-php
 * or any object exposing web()/news()/images()/gen()/wordstat() methods.
 */
final class YandexCloudSearchGateway extends AbstractSearchGateway
{
    use NormalizerTrait;

    public function __construct(private object $client)
    {
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchWeb(string $query, array $options = []): array
    {
        try {
            $raw = $this->client->web()->search($query, $options);
            return $this->normalizeList($raw, 'web');
        } catch (\Throwable $e) {
            throw new SearchGatewayException($e->getMessage(), (int) $e->getCode(), $e, 'yandex');
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchNews(string $query, array $options = []): array
    {
        try {
            $raw = $this->client->news()->search($query, $options);
            return $this->normalizeList($raw, 'news');
        } catch (\Throwable $e) {
            throw new SearchGatewayException($e->getMessage(), (int) $e->getCode(), $e, 'yandex');
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchImages(string $query, array $options = []): array
    {
        try {
            $raw = $this->client->images()->search($query, $options);
            return $this->normalizeList($raw, 'images');
        } catch (\Throwable $e) {
            throw new SearchGatewayException($e->getMessage(), (int) $e->getCode(), $e, 'yandex');
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    public function searchGen(string $query, array $options = []): GenerativeSearchResultDTO
    {
        try {
            $raw = $this->client->gen()->search($query, $options);
            $answerRaw = is_object($raw) ? ($raw->answer ?? null) : ($raw['answer'] ?? null);
            $answer = is_scalar($answerRaw) ? (string) $answerRaw : '';
            $sourcesRaw = is_object($raw) ? ($raw->sources ?? null) : ($raw['sources'] ?? []);
            $sources = is_array($sourcesRaw) ? array_values(array_filter($sourcesRaw, 'is_array')) : [];
            return new GenerativeSearchResultDTO(
                answer: $answer,
                sources: $sources,
                meta: ['provider' => 'yandex']
            );
        } catch (\Throwable $e) {
            throw new SearchGatewayException($e->getMessage(), (int) $e->getCode(), $e, 'yandex');
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>|array<string, mixed>
     */
    public function wordstat(string $query, array $options = []): array
    {
        try {
            $raw = $this->client->wordstat()->search($query, $options);
            return is_array($raw) ? $raw : $this->normalizeList($raw, 'wordstat');
        } catch (\Throwable $e) {
            throw new SearchGatewayException($e->getMessage(), (int) $e->getCode(), $e, 'yandex');
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array{url:string, title:string, domain:string, passage:string, score:float}>
     */
    public function llmContext(string $query, array $options = []): array
    {
        $options['docsOnPage'] = $options['docsOnPage'] ?? 10;
        $docs = $this->searchWeb($query, $options);

        return array_values(array_map(
            fn(array $doc): array => $this->toLlmChunk($doc),
            $docs
        ));
    }

    public function providerName(): string
    {
        return 'yandex';
    }
}
