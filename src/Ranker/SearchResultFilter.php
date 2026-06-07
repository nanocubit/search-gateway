<?php

declare(strict_types=1);

namespace SearchGateway\Ranker;

/**
 * Declarative filter for search results.
 */
final class SearchResultFilter
{
    /**
     * @param list<array<string, mixed>> $docs
     * @param array<string, mixed> $criteria
     * @return list<array<string, mixed>>
     */
    public function filter(array $docs, array $criteria = []): array
    {
        $allowedDomains = $criteria['domains'] ?? null;
        if (!is_array($allowedDomains) && $allowedDomains !== null) {
            $allowedDomains = null;
        }
        $blockedDomains = $criteria['exclude_domains'] ?? [];
        if (!is_array($blockedDomains)) {
            $blockedDomains = [];
        }
        $minScore = $criteria['min_score'] ?? null;
        $minScore = is_numeric($minScore) ? (float) $minScore : null;
        $maxAgeDays = $criteria['max_age_days'] ?? null;
        $maxAgeDays = is_int($maxAgeDays) ? $maxAgeDays : (is_numeric($maxAgeDays) ? (int) $maxAgeDays : null);
        $language = $criteria['language'] ?? null;
        $language = is_string($language) ? $language : null;

        return array_values(array_filter(
            $docs,
            static function (array $doc) use (
                $allowedDomains,
                $blockedDomains,
                $minScore,
                $maxAgeDays,
                $language,
            ): bool {
                $urlRaw = $doc['url'] ?? '';
                $url = is_scalar($urlRaw) ? (string) $urlRaw : '';
                $domainRaw = parse_url($url, PHP_URL_HOST);
                $domain = is_string($domainRaw) ? $domainRaw : '';

                if ($allowedDomains !== null && !in_array($domain, $allowedDomains, true)) {
                    return false;
                }
                if (in_array($domain, $blockedDomains, true)) {
                    return false;
                }
                if ($minScore !== null) {
                    $scoreRaw = $doc['score'] ?? 0;
                    $score = is_numeric($scoreRaw) ? (float) $scoreRaw : 0.0;
                    if ($score < $minScore) {
                        return false;
                    }
                }
                if ($maxAgeDays !== null && isset($doc['date']) && is_scalar($doc['date'])) {
                    try {
                        $age = (int) (new \DateTimeImmutable())->diff(new \DateTimeImmutable((string) $doc['date']))->days;
                        if ($age > $maxAgeDays) {
                            return false;
                        }
                    } catch (\Throwable) {
                        // ignore invalid dates
                    }
                }
                if ($language !== null && isset($doc['language']) && $doc['language'] !== $language) {
                    return false;
                }

                return true;
            }
        ));
    }
}
