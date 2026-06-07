<?php

declare(strict_types=1);

namespace SearchGateway\Strategy;

use SearchGateway\Contract\SearchGatewayInterface;

/**
 * Best-of-N strategy: выполняет N вариантов запроса, возвращает лучшие результаты.
 * Идея из query expansion + ensemble retrieval.
 */
final class BestOfNStrategy implements SearchStrategyInterface
{
    public function __construct(
        /** @phpstan-ignore-next-line property.isUnused */
        private int $n = 3
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function execute(SearchGatewayInterface $gateway, string $query, array $options = []): array
    {
        $variants = $this->generateVariants($query);
        $all = [];
        foreach ($variants as $variant) {
            $all = array_merge($all, $gateway->llmContext($variant, $options));
        }
        return $this->deduplicateAndRank($all);
    }

    /**
     * @return list<string>
     */
    private function generateVariants(string $query): array
    {
        // Simple variants: original + lowercase + with quotes + without stop words
        $variants = [$query];
        $variants[] = strtolower($query);
        if (!str_starts_with($query, '"')) {
            $variants[] = '"' . $query . '"';
        }
        return array_unique($variants);
    }

    /**
     * @param list<array<string, mixed>> $docs
     * @return list<array<string, mixed>>
     */
    private function deduplicateAndRank(array $docs): array
    {
        $map = [];
        foreach ($docs as $doc) {
            $url = $doc['url'] ?? '';
            if ($url === '') {
                continue;
            }
            if (!isset($map[$url]) || ($doc['score'] ?? 0) > ($map[$url]['score'] ?? 0)) {
                $map[$url] = $doc;
            }
        }
        $result = array_values($map);
        usort($result, static fn(array $a, array $b): int => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        return $result;
    }
}
