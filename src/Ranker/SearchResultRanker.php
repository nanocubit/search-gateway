<?php

declare(strict_types=1);

namespace SearchGateway\Ranker;

/**
 * Smart re-ranker for search results.
 * Supports: score-based, recency boost, domain authority, query-term density.
 */
final class SearchResultRanker
{
    /**
     * @param list<array<string, mixed>> $docs
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function rank(array $docs, string $query, array $options = []): array
    {
        $boostDomains = $options['boost_domains'] ?? [];
        if (!is_array($boostDomains)) {
            $boostDomains = [];
        }
        $penaltyDomains = $options['penalty_domains'] ?? [];
        if (!is_array($penaltyDomains)) {
            $penaltyDomains = [];
        }
        $recencyWeightRaw = $options['recency_weight'] ?? 0.0;
        $recencyWeight = is_numeric($recencyWeightRaw) ? (float) $recencyWeightRaw : 0.0;

        $scored = [];
        foreach ($docs as $doc) {
            $scoreRaw = $doc['score'] ?? 0;
            $score = is_numeric($scoreRaw) ? (float) $scoreRaw : 0.0;
            $url = is_scalar($doc['url'] ?? null) ? (string) $doc['url'] : '';
            $domainRaw = parse_url($url, PHP_URL_HOST);
            $domainStr = is_string($domainRaw) ? $domainRaw : '';
            $passage = is_scalar($doc['passage'] ?? null) ? (string) $doc['passage'] : '';

            if (in_array($domainStr, $boostDomains, true)) {
                $score *= 1.5;
            }
            if (in_array($domainStr, $penaltyDomains, true)) {
                $score *= 0.5;
            }

            $queryTerms = array_filter(explode(' ', strtolower($query)));
            $termCount = 0;
            foreach ($queryTerms as $term) {
                $termCount += substr_count(strtolower($passage), (string) $term);
            }
            $density = $termCount / max(1, str_word_count($passage));
            $score += $density * 0.1;

            if ($recencyWeight > 0 && isset($doc['date']) && is_scalar($doc['date'])) {
                $ageDays = $this->daysSince((string) $doc['date']);
                $score += max(0, (30 - $ageDays) / 30) * $recencyWeight;
            }

            $doc['_final_score'] = $score;
            $scored[] = $doc;
        }

        usort($scored, static function (array $a, array $b): int {
            $aScore = (float) $a['_final_score'];
            $bScore = (float) $b['_final_score'];
            return $bScore <=> $aScore;
        });

        return array_map(static function (array $doc): array {
            unset($doc['_final_score']);
            return $doc;
        }, $scored);
    }

    private function daysSince(string $date): int
    {
        try {
            $d = new \DateTimeImmutable($date);
            return (int) (new \DateTimeImmutable())->diff($d)->days;
        } catch (\Throwable) {
            return PHP_INT_MAX;
        }
    }
}
