<?php

declare(strict_types=1);

namespace SearchGateway\Tool;

use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Contract\SearchGatewayException;

/**
 * Perplexity-style multi-provider RAG engine.
 * Aggregates llmContext from multiple gateways, deduplicates and re-ranks.
 */
final class MultiSearchGateway implements SearchGatewayInterface
{
    /**
     * @param list<SearchGatewayInterface> $providers
     */
    public function __construct(private array $providers)
    {
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchWeb(string $query, array $options = []): array
    {
        return $this->aggregate('searchWeb', $query, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchNews(string $query, array $options = []): array
    {
        return $this->aggregate('searchNews', $query, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchImages(string $query, array $options = []): array
    {
        return $this->aggregate('searchImages', $query, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function searchGen(string $query, array $options = []): GenerativeSearchResultDTO
    {
        $allSources = [];
        $firstAnswer = '';
        $meta = ['providers' => [], 'hybrid' => true];

        foreach ($this->providers as $provider) {
            try {
                $result = $provider->searchGen($query, $options);
                $allSources = array_merge($allSources, $result->sources);
                $providerMeta = is_string($result->meta['provider'] ?? null) ? $result->meta['provider'] : 'unknown';
                $meta['providers'][] = $providerMeta;
                if ($firstAnswer === '' && trim($result->answer) !== '') {
                    $firstAnswer = $result->answer;
                }
            } catch (\Throwable) {
            }
        }

        return new GenerativeSearchResultDTO(
            answer: $firstAnswer,
            sources: $this->deduplicate($allSources),
            meta: $meta
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>|array<string, mixed>
     */
    public function wordstat(string $query, array $options = []): array
    {
        foreach ($this->providers as $provider) {
            $res = $provider->wordstat($query, $options);
            if ($res !== []) {
                return $res;
            }
        }
        return [];
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array{url:string, title:string, domain:string, passage:string, score:float}>
     */
    public function llmContext(string $query, array $options = []): array
    {
        $all = [];
        foreach ($this->providers as $provider) {
            try {
                $all = array_merge($all, $provider->llmContext($query, $options));
            } catch (\Throwable) {
                // resilient aggregation
            }
        }

        $limitRaw = $options['docsOnPage'] ?? 10;
        $limit = is_int($limitRaw) ? $limitRaw : (is_numeric($limitRaw) ? (int) $limitRaw : 10);

        $docs = $this->rankAndTrim($this->deduplicate($all), $limit);
        $out = [];
        foreach ($docs as $doc) {
            $out[] = [
                'url' => is_scalar($doc['url'] ?? null) ? (string) $doc['url'] : '',
                'title' => is_scalar($doc['title'] ?? null) ? (string) $doc['title'] : '',
                'domain' => is_scalar($doc['url'] ?? null) ? (string) (parse_url((string) $doc['url'], PHP_URL_HOST) ?: '') : '',
                'passage' => is_scalar($doc['passage'] ?? null) ? (string) $doc['passage'] : '',
                'score' => is_numeric($doc['score'] ?? null) ? (float) $doc['score'] : 0.0,
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    private function aggregate(string $method, string $query, array $options): array
    {
        $all = [];
        foreach ($this->providers as $provider) {
            try {
                $all = array_merge($all, $provider->$method($query, $options));
            } catch (\Throwable) {
                // ignore
            }
        }
        return $this->deduplicate($all);
    }

    /**
     * Deduplicate by URL, keep highest score.
     *
     * @param list<array<string, mixed>> $docs
     * @return list<array<string, mixed>>
     */
    private function deduplicate(array $docs): array
    {
        $map = [];
        foreach ($docs as $doc) {
            $url = $doc['url'] ?? '';
            if ($url === '') {
                continue;
            }
            if (!isset($map[$url]) || ($doc['score'] ?? 0) > ($map[$url]['score'] ?? 0)) {
                $map[$url] = $doc;
            }
        }
        return array_values($map);
    }

    /**
     * Simple re-rank by score then trim.
     *
     * @param list<array<string, mixed>> $docs
     * @return list<array<string, mixed>>
     */
    private function rankAndTrim(array $docs, int $limit): array
    {
        usort($docs, static fn(array $a, array $b): int => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        return array_slice($docs, 0, $limit);
    }
}
