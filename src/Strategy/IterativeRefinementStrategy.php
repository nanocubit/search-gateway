<?php

declare(strict_types=1);

namespace SearchGateway\Strategy;

use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Infrastructure\LLMClientInterface;

/**
 * Iterative refinement: поиск -> анализ gaps -> уточнение -> повтор.
 * Идея из multi-hop retrieval: сложные вопросы требуют нескольких итераций.
 */
final class IterativeRefinementStrategy implements SearchStrategyInterface
{
    public function __construct(
        private LLMClientInterface $llm,
        private int $maxRounds = 2
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function execute(SearchGatewayInterface $gateway, string $query, array $options = []): array
    {
        $collected = [];
        $currentQuery = $query;

        for ($round = 0; $round < $this->maxRounds; $round++) {
            $docs = $gateway->llmContext($currentQuery, $options);
            $collected = array_merge($collected, $docs);

            // Check if we have enough information
            $check = $this->llm->generate(<<<PROMPT
Given the question and these sources, can we answer fully? Respond ONLY "yes" or "no".

Question: {$query}

Sources:
{$this->summarizeSources($docs)}
PROMPT
            );

            if (strtolower(trim($check)) === 'yes') {
                break;
            }

            // Generate follow-up query for missing information
            $currentQuery = $this->llm->generate(<<<PROMPT
The question is: {$query}
We found these sources but they are insufficient.
Generate a more specific follow-up search query to find missing information.
Respond with ONLY the query.

Sources:
{$this->summarizeSources($docs)}
PROMPT
            );
        }

        return $this->deduplicate($collected);
    }

    /**
     * @param list<array<string, mixed>> $docs
     */
    private function summarizeSources(array $docs): string
    {
        return implode("
", array_map(
            static fn(array $doc): string => "- " . ($doc['title'] ?? '') . ": " . ($doc['passage'] ?? ''),
            array_slice($docs, 0, 5)
        ));
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
            if (!isset($map[$url])) {
                $map[$url] = $doc;
            }
        }
        return array_values($map);
    }
}
