<?php

declare(strict_types=1);

namespace SearchGateway\Explainability;

/**
 * Explain why a result was ranked: term matching, domain authority, recency, etc.
 * Идея из LIME / Perplexity citations: explainable AI для search.
 */
final class ResultExplainer
{
    /**
     * @param array<string, mixed> $doc
     * @return list<array{factor: string, score: float, description: string}>
     */
    public function explain(array $doc, string $query): array
    {
        $factors = [];

        $queryTerms = array_filter(explode(' ', strtolower($query)));
        $passage = strtolower(is_scalar($doc['passage'] ?? null) ? (string) $doc['passage'] : '');
        $matchedTerms = array_filter($queryTerms, static fn(string $t): bool => str_contains($passage, $t));
        $termScore = count($matchedTerms) / max(1, count($queryTerms));
        $factors[] = [
            'factor' => 'term_match',
            'score' => round($termScore, 2),
            'description' => 'Matched ' . count($matchedTerms) . ' of ' . count($queryTerms) . ' query terms',
        ];

        $url = is_scalar($doc['url'] ?? null) ? (string) $doc['url'] : '';
        $domainRaw = parse_url($url, PHP_URL_HOST);
        $domainStr = is_string($domainRaw) ? $domainRaw : '';
        $trustedDomains = ['.edu', '.gov', 'wikipedia.org', 'php.net', 'github.com', 'stackoverflow.com'];
        $domainScore = 0;
        foreach ($trustedDomains as $td) {
            if (str_contains($domainStr, $td)) {
                $domainScore = 1;
                break;
            }
        }
        $factors[] = [
            'factor' => 'domain_authority',
            'score' => $domainScore,
            'description' => $domainScore > 0 ? "Trusted domain: {$domainStr}" : "Domain: {$domainStr}",
        ];

        if (isset($doc['date']) && is_scalar($doc['date'])) {
            try {
                $age = (new \DateTimeImmutable())->diff(new \DateTimeImmutable((string) $doc['date']))->days;
                $recencyScore = max(0, 1 - ($age / 365));
                $factors[] = [
                    'factor' => 'recency',
                    'score' => round($recencyScore, 2),
                    'description' => $age < 30 ? "Very recent ({$age} days)" : "Age: {$age} days",
                ];
            } catch (\Throwable) {
                // ignore invalid dates
            }
        }

        if (isset($doc['score']) && is_numeric($doc['score'])) {
            $factors[] = [
                'factor' => 'provider_relevance',
                'score' => (float) $doc['score'],
                'description' => 'Original provider relevance score',
            ];
        }

        return $factors;
    }

    /**
     * Human-readable explanation string.
     *
     * @param array<string, mixed> $doc
     */
    public function explainAsText(array $doc, string $query): string
    {
        $factors = $this->explain($doc, $query);
        $lines = [];
        foreach ($factors as $f) {
            $lines[] = "- {$f['factor']}: {$f['score']} — {$f['description']}";
        }
        return implode("\n", $lines);
    }
}
