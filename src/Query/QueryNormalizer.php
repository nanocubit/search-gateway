<?php

declare(strict_types=1);

namespace SearchGateway\Query;

/**
 * Нормализация запросов: stemming, stop words, synonym expansion, spell check.
 */
final class QueryNormalizer
{
    /** @var list<string> */
    private array $stopWords = [
        'the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
        'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should',
    ];

    /** @var array<string, list<string>> */
    private array $synonyms = [
        'php' => ['php8', 'php 8', 'php8.4'],
        'javascript' => ['js', 'ecmascript', 'node.js'],
        'ai' => ['artificial intelligence', 'machine learning', 'ml'],
    ];

    public function normalize(string $query): string
    {
        $query = strtolower(trim($query));
        $words = array_filter(explode(' ', $query));
        $words = array_diff($words, $this->stopWords);
        return implode(' ', array_values($words));
    }

    /**
     * @return list<string>
     */
    public function expandSynonyms(string $query): array
    {
        $variants = [$query];
        foreach ($this->synonyms as $word => $syns) {
            if (str_contains($query, $word)) {
                foreach ($syns as $syn) {
                    $variants[] = str_replace($word, $syn, $query);
                }
            }
        }
        return array_unique($variants);
    }

    /**
     * @param array<string, mixed> $dictionary
     */
    public function suggestSpelling(string $query, array $dictionary): ?string
    {
        $words = explode(' ', $query);
        $suggestions = [];
        foreach ($words as $word) {
            if (strlen($word) < 3) {
                $suggestions[] = $word;
                continue;
            }
            $best = $word;
            $bestDist = PHP_INT_MAX;
            foreach ($dictionary as $dictWord) {
                if (!is_string($dictWord)) {
                    continue;
                }
                $dist = levenshtein($word, $dictWord);
                if ($dist < $bestDist && $dist <= 2) {
                    $bestDist = $dist;
                    $best = $dictWord;
                }
            }
            $suggestions[] = $best;
        }
        $suggested = implode(' ', $suggestions);
        return $suggested !== $query ? $suggested : null;
    }
}
