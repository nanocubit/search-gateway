<?php

declare(strict_types=1);

namespace SearchGateway\Enrichment;

use SearchGateway\Infrastructure\LLMClientInterface;

/**
 * Enrich search results with AI-generated summaries, key facts, and credibility scores.
 * Идея из Perplexity: не просто ссылки, а структурированная информация.
 */
final class ResultEnricher
{
    public function __construct(private LLMClientInterface $llm)
    {
    }

    /**
     * @param list<array<string, mixed>> $docs
     * @return list<array<string, mixed>>
     */
    public function enrich(array $docs, string $query): array
    {
        return array_map(function (array $doc) use ($query): array {
            $passageRaw = $doc['passage'] ?? '';
            $passage = is_scalar($passageRaw) ? (string) $passageRaw : '';
            if ($passage === '') {
                return $doc;
            }

            $summary = $this->summarize($passage, $query);
            $facts = $this->extractFacts($passage);
            $credibility = $this->assessCredibility($doc);

            return array_merge($doc, [
                '_enriched' => [
                    'summary' => $summary,
                    'key_facts' => $facts,
                    'credibility_score' => $credibility,
                    'relevance_score' => $this->estimateRelevance($passage, $query),
                ],
            ]);
        }, $docs);
    }

    private function summarize(string $text, string $query): string
    {
        $prompt = <<<PROMPT
Summarize the following text in 2-3 sentences, focusing on information relevant to: {$query}

Text:
{$text}

Summary:
PROMPT;
        return trim($this->llm->generate($prompt));
    }

    /**
     * @return list<string>
     */
    private function extractFacts(string $text): array
    {
        $prompt = <<<PROMPT
Extract 3-5 key factual claims from the text below as a JSON array of strings.
Respond with ONLY the JSON array, no markdown.

Text:
{$text}
PROMPT;
        $raw = $this->llm->generate($prompt);
        $cleaned = preg_replace('/^```json\s*|\s*```$/m', '', $raw);
        $clean = is_string($cleaned) ? trim($cleaned) : trim($raw);
        $decoded = json_decode($clean, true);
        if (!is_array($decoded)) {
            return [];
        }
        $facts = [];
        foreach ($decoded as $fact) {
            if (is_string($fact)) {
                $facts[] = $fact;
            }
        }
        return $facts;
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function assessCredibility(array $doc): float
    {
        $urlRaw = $doc['url'] ?? '';
        $url = is_scalar($urlRaw) ? (string) $urlRaw : '';
        $domainRaw = parse_url($url, PHP_URL_HOST);
        $domain = is_string($domainRaw) ? $domainRaw : '';
        $score = 0.5;

        // Boost trusted domains
        $trusted = ['gov', 'edu', 'org', 'wikipedia.org', 'php.net', 'github.com'];
        foreach ($trusted as $t) {
            if (str_contains($domain, $t)) {
                $score += 0.2;
                break;
            }
        }

        // Penalize known UGC / low-credibility patterns
        $suspicious = ['forum', 'blogspot', 'wordpress.com'];
        foreach ($suspicious as $s) {
            if (str_contains($domain, $s)) {
                $score -= 0.15;
            }
        }

        return min(1.0, max(0.0, $score));
    }

    private function estimateRelevance(string $passage, string $query): float
    {
        $queryTerms = array_filter(explode(' ', strtolower($query)));
        $termCount = 0;
        foreach ($queryTerms as $term) {
            $termCount += substr_count(strtolower($passage), $term);
        }
        $density = $termCount / max(1, str_word_count($passage));
        return min(1.0, $density * 5); // Scale up for meaningful range
    }
}
