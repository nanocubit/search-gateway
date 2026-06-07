<?php

declare(strict_types=1);

namespace SearchGateway\Reranker;

use SearchGateway\Infrastructure\LLMClientInterface;

/**
 * LLM-based cross-encoder reranker.
 * Reranks documents by relevance to the query using an LLM as a judge.
 * Compatible with YandexGPT, OpenAI, or local Ollama.
 *
 * Two modes:
 *   - single-shot: one LLM call per document (more accurate, slower).
 *   - batch: a single prompt that asks the LLM to score all documents at once
 *     and parse the response (fewer tokens, cheaper).
 */
final class CrossEncoderReranker
{
    public const MODE_SINGLE = 'single';
    public const MODE_BATCH = 'batch';

    private const DEFAULT_SYSTEM_PROMPT = <<<PROMPT
Rate the relevance of the document to the query on a scale of 0 to 1.
Respond with ONLY a number between 0.00 and 1.00.
PROMPT;

    public function __construct(
        private LLMClientInterface $llm,
        private ?string $systemPrompt = null,
        private string $mode = self::MODE_SINGLE,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $docs
     * @return list<array<string, mixed>>
     */
    public function rerank(array $docs, string $query, int $topK = 5): array
    {
        if ($docs === []) {
            return [];
        }

        if ($this->mode === self::MODE_BATCH) {
            $scores = $this->scoreBatch($docs, $query);
        } else {
            $scores = $this->scoreSingle($docs, $query);
        }

        $scored = [];
        foreach ($docs as $i => $doc) {
            $scored[] = ['doc' => $doc, 'score' => $scores[$i] ?? 0.5];
        }

        usort($scored, static function (array $a, array $b): int {
            return $b['score'] <=> $a['score'];
        });

        $top = array_slice($scored, 0, $topK);
        $out = [];
        foreach ($top as $entry) {
            $doc = $entry['doc'];
            $doc['_rerank_score'] = $entry['score'];
            $out[] = $doc;
        }
        return $out;
    }

    /**
     * Single-doc scoring: one LLM call per document.
     *
     * @param list<array<string, mixed>> $docs
     * @return list<float>
     */
    private function scoreSingle(array $docs, string $query): array
    {
        $scores = [];
        foreach ($docs as $doc) {
            $scores[] = $this->scoreOne($doc, $query);
        }
        return $scores;
    }

    /**
     * Batch scoring: one LLM call for all documents, parse the response.
     * The expected response format is a comma- or newline-separated list of numbers,
     * one per document, in the same order.
     *
     * @param list<array<string, mixed>> $docs
     * @return list<float>
     */
    private function scoreBatch(array $docs, string $query): array
    {
        $documents = [];
        foreach ($docs as $i => $doc) {
            $title = $this->extractString($doc, 'title');
            $passage = $this->extractString($doc, 'passage')
                ?: $this->extractString($doc, 'description')
                ?: $this->extractString($doc, 'snippet');
            $documents[] = sprintf("[%d] title=%s; passage=%s", $i, $title, $passage);
        }

        $documentsBlock = implode("\n", $documents);
        $system = $this->systemPrompt ?? self::DEFAULT_SYSTEM_PROMPT;

        $prompt = <<<PROMPT
{$system}

Query: {$query}

Documents (one per line, format "[index] title=...; passage=..."):
{$documentsBlock}

Respond with exactly {$this->countOf($docs)} relevance scores, one per line,
in the same order as the documents. Each score must be a number between 0.00 and 1.00.
PROMPT;

        $raw = $this->llm->generate($prompt);
        $numbers = $this->parseNumbers($raw, count($docs));

        $scores = [];
        foreach ($numbers as $i => $n) {
            $scores[$i] = min(1.0, max(0.0, $n));
        }

        // pad missing scores
        for ($i = 0; $i < count($docs); $i++) {
            if (!isset($scores[$i])) {
                $scores[$i] = 0.5;
            }
        }
        ksort($scores);
        return array_values($scores);
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function scoreOne(array $doc, string $query): float
    {
        $passage = $this->extractString($doc, 'passage')
            ?: $this->extractString($doc, 'description')
            ?: $this->extractString($doc, 'snippet');
        $title = $this->extractString($doc, 'title');

        $system = $this->systemPrompt ?? self::DEFAULT_SYSTEM_PROMPT;
        $prompt = <<<PROMPT
{$system}

Query: {$query}
Document title: {$title}
Document passage: {$passage}
Relevance score:
PROMPT;

        $raw = $this->llm->generate($prompt);
        return $this->parseSingleNumber($raw);
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function extractString(array $doc, string $key): string
    {
        $raw = $doc[$key] ?? '';
        return is_scalar($raw) ? (string) $raw : '';
    }

    /**
     * @param list<array<string, mixed>> $docs
     */
    private function countOf(array $docs): int
    {
        return count($docs);
    }

    /**
     * @return list<float>
     */
    private function parseNumbers(string $raw, int $expected): array
    {
        $lines = preg_split('/[\r\n]+/', $raw) ?: [];
        $numbers = [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                continue;
            }
            if (preg_match('/(-?\d+(?:\.\d+)?)/', $trim, $m)) {
                $numbers[] = (float) $m[1];
            }
        }
        if (count($numbers) === 0) {
            return [];
        }
        return $numbers;
    }

    private function parseSingleNumber(string $raw): float
    {
        $cleaned = preg_replace('/[^\d.\-]/', ' ', $raw);
        if (is_string($cleaned) && preg_match('/(-?\d+(?:\.\d+)?)/', $cleaned, $m)) {
            return min(1.0, max(0.0, (float) $m[1]));
        }
        return 0.5;
    }
}
