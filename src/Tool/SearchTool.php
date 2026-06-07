<?php

declare(strict_types=1);

namespace SearchGateway\Tool;

use SearchGateway\Contract\SearchGatewayInterface;

/**
 * High-level search tool for agents.
 * Wraps any gateway and provides formatting helpers.
 */
final readonly class SearchTool
{
    public function __construct(private SearchGatewayInterface $search)
    {
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function web(string $query, array $options = []): array
    {
        return $this->search->searchWeb($query, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function news(string $query, array $options = []): array
    {
        return $this->search->searchNews($query, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function images(string $query, array $options = []): array
    {
        return $this->search->searchImages($query, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array{url:string, title:string, domain:string, passage:string, score:float}>
     */
    public function context(string $query, array $options = []): array
    {
        return $this->search->llmContext($query, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function gen(string $query, array $options = []): string
    {
        return $this->search->searchGen($query, $options)->answer;
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>|array<string, mixed>
     */
    public function wordstat(string $query, array $options = []): array
    {
        return $this->search->wordstat($query, $options);
    }

    /**
     * Format a list of documents into a markdown-style string for LLM prompt injection.
     *
     * @param list<array<string, mixed>> $docs
     */
    public function formatDocs(array $docs, int $limit = 5): string
    {
        $docs = array_slice($docs, 0, $limit);
        $lines = [];

        foreach ($docs as $doc) {
            $title = is_scalar($doc['title'] ?? null) ? (string) $doc['title'] : '';
            $url = is_scalar($doc['url'] ?? null) ? (string) $doc['url'] : '';
            $passage = is_scalar($doc['passage'] ?? null) ? (string) $doc['passage'] : '';
            $lines[] = trim("- {$title} ({$url})
  {$passage}");
        }

        return implode("\n", $lines);
    }

    /**
     * Format with numeric citations [1], [2] …
     *
     * @param list<array<string, mixed>> $docs
     */
    public function formatCitations(array $docs, int $limit = 5): string
    {
        $docs = array_slice($docs, 0, $limit);
        $lines = [];

        foreach ($docs as $i => $doc) {
            $n = $i + 1;
            $title = is_scalar($doc['title'] ?? null) ? (string) $doc['title'] : '';
            $url = is_scalar($doc['url'] ?? null) ? (string) $doc['url'] : '';
            $passage = is_scalar($doc['passage'] ?? null) ? (string) $doc['passage'] : '';
            $lines[] = "[{$n}] {$title}
    URL: {$url}
    {$passage}";
        }

        return implode("\n\n", $lines);
    }
}
