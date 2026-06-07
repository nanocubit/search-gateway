<?php

declare(strict_types=1);

namespace SearchGateway\QueryExpansion;

use SearchGateway\Infrastructure\LLMClientInterface;

/**
 * Multi-query retrieval: один сложный запрос -> N уточнённых sub-queries.
 * Параллельный search по всем -> merge -> rerank.
 * Идея из LangChain MultiQueryRetriever + Perplexity related questions.
 */
final class QueryExpansionEngine
{
    public function __construct(private LLMClientInterface $llm)
    {
    }

    /**
     * Разбить запрос на sub-queries для лучшего coverage.
     *
     * @return list<string>
     */
    public function expand(string $query, int $numVariants = 3): array
    {
        $prompt = <<<PROMPT
Generate {$numVariants} different search queries that would help answer the main question.
Each query should explore a different angle. Respond with one query per line, no numbering.

Main question: {$query}
PROMPT;

        $raw = $this->llm->generate($prompt);
        $lines = array_filter(array_map('trim', explode("
", $raw)));

        // Always include original
        $variants = [$query];
        foreach ($lines as $line) {
            if ($line !== '' && $line !== $query && !in_array($line, $variants, true)) {
                $variants[] = $line;
            }
            if (count($variants) > $numVariants) {
                break;
            }
        }

        return array_slice($variants, 0, $numVariants + 1);
    }

    /**
     * Сгенерировать follow-up questions (как Perplexity related_questions).
     *
     * @return list<string>
     */
    public function suggestFollowUps(string $query, ?string $answer = null): array
    {
        $ctx = $answer ? "Previous answer: {$answer}" : '';
        $prompt = <<<PROMPT
Based on the question below, suggest 3 natural follow-up questions a user might ask.
One per line, no numbering.

Question: {$query}
{$ctx}
PROMPT;

        $raw = $this->llm->generate($prompt);
        return array_values(array_filter(array_map('trim', explode("
", $raw))));
    }
}
