<?php

declare(strict_types=1);

namespace SearchGateway\Retrieval;

use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Embedding\EmbeddingInterface;
use SearchGateway\Embedding\VectorStoreInterface;
use SearchGateway\Infrastructure\LLMClientInterface;

/**
 * Self-adaptive retriever: routes the query to web, vector, or hybrid search
 * based on the detected intent.
 *
 * Two-tier intent classification:
 *   1. Fast keyword-based heuristic (no LLM cost) for clear cases.
 *   2. LLM-based classification as fallback for ambiguous queries.
 *
 * The vector branch needs an EmbeddingInterface; if not provided, the retriever
 * degrades gracefully to web-only mode.
 */
final class AdaptiveRetriever
{
    public const STRATEGY_WEB = 'web';
    public const STRATEGY_VECTOR = 'vector';
    public const STRATEGY_HYBRID = 'hybrid';

    private const WEB_KEYWORDS = [
        'news', 'latest', 'today', 'current', 'recent', 'breaking',
        '2024', '2025', '2026', '2027', '2028', '2029', '2030',
        'новост', 'сегодня', 'сейчас', 'актуальн', 'вчера', 'последн',
    ];

    private const VECTOR_KEYWORDS = [
        'document', 'internal', 'knowledge base', 'our', 'company',
        'policy', 'handbook', 'wiki', 'history', 'previous',
        'документ', 'внутренн', 'база знаний', 'наш', 'компани',
        'политик', 'справочник', 'вики', 'истори', 'предыдущ',
    ];

    private const HYBRID_KEYWORDS = [
        'compare', 'versus', 'vs', 'benchmark', 'analysis',
        'сравни', 'сопостав', 'против', 'бенчмарк', 'анализ',
    ];

    public function __construct(
        private SearchGatewayInterface $webSearch,
        private VectorStoreInterface $vectorStore,
        private LLMClientInterface $llm,
        private ?EmbeddingInterface $embedding = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return array{strategy: string, intent: string, results: list<array<string, mixed>>}
     */
    public function retrieve(string $query, array $options = []): array
    {
        $strategy = $this->route($query);
        $kRaw = $options['k'] ?? 5;
        $k = is_int($kRaw) ? $kRaw : 5;

        $results = match ($strategy) {
            self::STRATEGY_VECTOR => $this->vectorStore->search($this->embed($query), $k),
            self::STRATEGY_WEB => $this->webSearch->llmContext($query, $options),
            self::STRATEGY_HYBRID => $this->hybrid($query, $k, $options),
            default => $this->webSearch->llmContext($query, $options),
        };

        return [
            'strategy' => $strategy,
            'intent' => $this->lastIntent,
            'results' => $results,
        ];
    }

    private string $lastIntent = 'unknown';

    /**
     * Detect strategy: try fast heuristics first, fall back to LLM.
     */
    public function route(string $query): string
    {
        $intent = $this->classifyHeuristic($query);
        $this->lastIntent = $intent;

        if ($intent !== 'ambiguous') {
            return match ($intent) {
                'web' => self::STRATEGY_WEB,
                'vector' => $this->embedding !== null ? self::STRATEGY_VECTOR : self::STRATEGY_WEB,
                'hybrid' => $this->embedding !== null ? self::STRATEGY_HYBRID : self::STRATEGY_WEB,
            };
        }

        return $this->classifyWithLlm($query);
    }

    /**
     * @return 'web'|'vector'|'hybrid'|'ambiguous'
     */
    private function classifyHeuristic(string $query): string
    {
        $lower = strtolower($query);
        $webHits = $this->countKeywordHits($lower, self::WEB_KEYWORDS);
        $vecHits = $this->countKeywordHits($lower, self::VECTOR_KEYWORDS);
        $hybHits = $this->countKeywordHits($lower, self::HYBRID_KEYWORDS);

        if ($hybHits > 0 || ($webHits > 0 && $vecHits > 0)) {
            return 'hybrid';
        }
        if ($webHits > 0) {
            return 'web';
        }
        if ($vecHits > 0) {
            return 'vector';
        }
        return 'ambiguous';
    }

    /**
     * @param list<string> $keywords
     */
    private function countKeywordHits(string $haystack, array $keywords): int
    {
        $hits = 0;
        foreach ($keywords as $kw) {
            if ($kw !== '' && str_contains($haystack, $kw)) {
                $hits++;
            }
        }
        return $hits;
    }

    private function classifyWithLlm(string $query): string
    {
        $hasEmbedding = $this->embedding !== null;
        $vectorLine = $hasEmbedding
            ? '"vector": needs internal documents, knowledge base, historical data'
            : '"vector": disabled (no embedding provider)';

        $prompt = <<<PROMPT
Classify the query retrieval strategy. Respond with ONLY one word: web, vector, or hybrid.

- "web": needs real-time or external information (news, current events, web pages)
- {$vectorLine}
- "hybrid": needs both

Query: {$query}
Strategy:
PROMPT;

        $raw = strtolower(trim($this->llm->generate($prompt)));
        return match ($raw) {
            'web' => self::STRATEGY_WEB,
            'vector' => $hasEmbedding ? self::STRATEGY_VECTOR : self::STRATEGY_WEB,
            'hybrid' => $hasEmbedding ? self::STRATEGY_HYBRID : self::STRATEGY_WEB,
            default => self::STRATEGY_WEB,
        };
    }

    /**
     * Hybrid retrieval: web + vector, dedup by URL.
     *
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    private function hybrid(string $query, int $k, array $options): array
    {
        $web = $this->webSearch->llmContext($query, $options);
        $vec = $this->vectorStore->search($this->embed($query), max(1, intdiv($k, 2)));

        $seen = [];
        $out = [];
        foreach (array_merge($web, $vec) as $doc) {
            if (!is_array($doc)) {
                continue;
            }
            $urlRaw = $doc['url'] ?? null;
            $url = is_string($urlRaw) ? $urlRaw : '';
            if ($url !== '' && isset($seen[$url])) {
                continue;
            }
            if ($url !== '') {
                $seen[$url] = true;
            }
            $out[] = $doc;
        }
        return $out;
    }

    /**
     * @return list<float>
     */
    private function embed(string $query): array
    {
        if ($this->embedding === null) {
            return [];
        }
        return $this->embedding->embed($query);
    }
}
