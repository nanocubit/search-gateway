<?php

declare(strict_types=1);

namespace SearchGateway\Tool;

use SearchGateway\Contract\AsyncHttpClientInterface;
use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Contract\SearchGatewayInterface;

/**
 * Async multi-provider gateway. Fan-out is real only for providers that
 * implement {@see AsyncRequestBuilderInterface}; the rest are called sequentially
 * to preserve correctness.
 */
final class AsyncMultiSearchGateway implements SearchGatewayInterface
{
    /**
     * @param list<SearchGatewayInterface> $providers
     */
    public function __construct(
        private array $providers,
        private ?AsyncHttpClientInterface $asyncHttp = null
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchWeb(string $query, array $options = []): array
    {
        return $this->parallel('searchWeb', $query, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchNews(string $query, array $options = []): array
    {
        return $this->parallel('searchNews', $query, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchImages(string $query, array $options = []): array
    {
        return $this->parallel('searchImages', $query, $options);
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
                $providerMeta = $result->meta['provider'] ?? 'unknown';
                $meta['providers'][] = is_string($providerMeta) ? $providerMeta : 'unknown';
                if ($firstAnswer === '' && trim($result->answer) !== '') {
                    $firstAnswer = $result->answer;
                }
            } catch (\Throwable) {
                // ignore individual provider failure
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
     * @return array<int|string, mixed>
     */
    public function wordstat(string $query, array $options = []): array
    {
        return $this->parallel('wordstat', $query, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function llmContext(string $query, array $options = []): array
    {
        return $this->parallel('llmContext', $query, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    private function parallel(string $method, string $query, array $options): array
    {
        $jobs = [];
        $fallback = [];

        foreach ($this->providers as $provider) {
            if ($this->asyncHttp !== null && $provider instanceof AsyncRequestBuilderInterface) {
                $requests = $provider->buildRequests($method, $query, $options);
                foreach ($requests as $r) {
                    $jobs[$provider->providerName() . '#' . count($jobs)] = $r;
                }
            } else {
                $fallback[] = $provider;
            }
        }

        $all = [];

        if ($jobs !== [] && $this->asyncHttp !== null) {
            $results = $this->asyncHttp->runConcurrent($jobs, [
                'concurrency' => min(count($jobs), 5),
            ]);
            foreach ($results as $r) {
                if ($r['success'] === true) {
                    $value = $r['value'];
                    if (is_array($value)) {
                        /** @var list<array<string, mixed>> $value */
                        $all = array_merge($all, $value);
                    }
                }
            }
        }

        foreach ($fallback as $provider) {
            try {
                $providerResults = $provider->$method($query, $options);
                if (is_array($providerResults)) {
                    $all = array_merge($all, $providerResults);
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        return $this->deduplicate($all);
    }

    /**
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
}
