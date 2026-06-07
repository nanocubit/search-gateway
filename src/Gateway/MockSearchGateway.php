<?php

declare(strict_types=1);

namespace SearchGateway\Gateway;

use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Contract\SearchGatewayInterface;

/**
 * In-memory mock gateway for testing and development.
 * Returns configurable static responses.
 */
final class MockSearchGateway implements SearchGatewayInterface
{
    /**
     * @param array<string, mixed> $responses
     */
    public function __construct(private array $responses = [])
    {
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchWeb(string $query, array $options = []): array
    {
        $stored = $this->responses['searchWeb'] ?? null;
        if (is_array($stored)) {
            return array_values(array_filter($stored, 'is_array'));
        }
        return $this->defaultResponse('web', $query);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchNews(string $query, array $options = []): array
    {
        $stored = $this->responses['searchNews'] ?? null;
        if (is_array($stored)) {
            return array_values(array_filter($stored, 'is_array'));
        }
        return $this->defaultResponse('news', $query);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchImages(string $query, array $options = []): array
    {
        $stored = $this->responses['searchImages'] ?? null;
        if (is_array($stored)) {
            return array_values(array_filter($stored, 'is_array'));
        }
        return $this->defaultResponse('images', $query);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function searchGen(string $query, array $options = []): GenerativeSearchResultDTO
    {
        $stored = $this->responses['searchGen'] ?? null;
        if ($stored instanceof GenerativeSearchResultDTO) {
            return $stored;
        }
        return new GenerativeSearchResultDTO(
            answer: "Mock answer for: {$query}",
            sources: $this->defaultResponse('web', $query),
            meta: ['provider' => 'mock']
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>|array<string, mixed>
     */
    public function wordstat(string $query, array $options = []): array
    {
        $stored = $this->responses['wordstat'] ?? null;
        return is_array($stored) ? $stored : [];
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array{url:string, title:string, domain:string, passage:string, score:float}>
     */
    public function llmContext(string $query, array $options = []): array
    {
        $stored = $this->responses['llmContext'] ?? null;
        if (is_array($stored)) {
            /** @var list<array{url:string, title:string, domain:string, passage:string, score:float}> $stored */
            return array_values(array_filter($stored, 'is_array'));
        }
        $default = $this->defaultResponse('web', $query);
        $out = [];
        foreach ($default as $doc) {
            $url = $doc['url'];
            $out[] = [
                'url' => $url,
                'title' => $doc['title'],
                'domain' => (string) (parse_url($url, PHP_URL_HOST) ?: ''),
                'passage' => $doc['passage'],
                'score' => $doc['score'],
            ];
        }
        return $out;
    }

    /**
     * @return list<array{type:string, title:string, url:string, passage:string, score:float}>
     */
    private function defaultResponse(string $type, string $query): array
    {
        return [
            [
                'type' => $type,
                'title' => "Mock {$type} result for \"{$query}\"",
                'url' => "https://mock.example.com/{$type}/" . urlencode($query),
                'passage' => "This is a mock passage for query: {$query}",
                'score' => 1.0,
            ],
        ];
    }
}
