<?php

declare(strict_types=1);

namespace SearchGateway\Suggest;

use SearchGateway\Contract\SearchGatewayInterface;

/**
 * Autocomplete / suggestion engine на базе поисковой выдачи.
 * Извлекает related queries из результатов для dropdown suggestions.
 */
final class SearchSuggestionEngine
{
    public function __construct(private SearchGatewayInterface $gateway)
    {
    }

    /**
     * @return list<string>
     */
    public function suggest(string $partialQuery, int $limit = 5): array
    {
        if (strlen($partialQuery) < 3) {
            return [];
        }

        $results = $this->gateway->searchWeb($partialQuery, ['docsOnPage' => $limit * 2]);
        $suggestions = [];

        foreach ($results as $doc) {
            $titleRaw = $doc['title'] ?? '';
            $title = is_scalar($titleRaw) ? (string) $titleRaw : '';
            $phrases = $this->extractPhrases($title, $partialQuery);
            foreach ($phrases as $phrase) {
                if (!in_array($phrase, $suggestions, true) && strcasecmp($phrase, $partialQuery) !== 0) {
                    $suggestions[] = $phrase;
                }
                if (count($suggestions) >= $limit) {
                    return $suggestions;
                }
            }
        }

        return $suggestions;
    }

    /**
     * @return list<string>
     */
    private function extractPhrases(string $title, string $query): array
    {
        $cleaned = preg_replace('/[^\w\s]/u', '', strtolower($title));
        $clean = is_string($cleaned) ? $cleaned : '';
        $words = array_filter(explode(' ', $clean));
        $queryWords = array_filter(explode(' ', strtolower($query)));

        $phrases = [];
        $buffer = [];
        foreach ($words as $word) {
            if (in_array($word, $queryWords, true) || strlen($word) < 3) {
                if ($buffer !== []) {
                    $phrases[] = implode(' ', $buffer);
                    $buffer = [];
                }
            } else {
                $buffer[] = $word;
            }
        }
        if ($buffer !== []) {
            $phrases[] = implode(' ', $buffer);
        }

        return $phrases;
    }
}
