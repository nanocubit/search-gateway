<?php

declare(strict_types=1);

namespace SearchGateway\Infrastructure;

/**
 * Shared normalisation logic for raw API responses.
 */
trait NormalizerTrait
{
    /**
     * @return list<array<string, mixed>>
     */
    protected function normalizeList(mixed $raw, string $type): array
    {
        $items = [];
        $data = is_iterable($raw) ? $raw : (array) $raw;

        foreach ($data as $item) {
            if (is_object($item)) {
                $title = $item->title ?? (method_exists($item, 'getTitle') ? $item->getTitle() : '');
                $url = $item->url ?? (method_exists($item, 'getUrl') ? $item->getUrl() : '');
                $passage = $item->passage ?? (method_exists($item, 'getPassage') ? $item->getPassage() : '');
                $score = $item->score ?? (method_exists($item, 'getRelevance') ? $item->getRelevance() : 1.0);
                $items[] = [
                    'type'    => $type,
                    'title'   => is_scalar($title) ? (string) $title : '',
                    'url'     => is_scalar($url) ? (string) $url : '',
                    'passage' => is_scalar($passage) ? (string) $passage : '',
                    'score'   => is_numeric($score) ? (float) $score : 1.0,
                    'raw'     => $item,
                ];
            } elseif (is_array($item)) {
                $item['type'] = $type;
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Build a Brave-like LLM context chunk from a normalised document.
     *
     * @param array<string, mixed> $doc
     * @return array{url:string, title:string, domain:string, passage:string, score:float}
     */
    protected function toLlmChunk(array $doc): array
    {
        $urlRaw = $doc['url'] ?? '';
        $url = is_scalar($urlRaw) ? (string) $urlRaw : '';

        $passageRaw = $doc['passage'] ?? $doc['description'] ?? $doc['snippet'] ?? '';
        $passage = is_scalar($passageRaw) ? (string) $passageRaw : '';

        $titleRaw = $doc['title'] ?? '';
        $title = is_scalar($titleRaw) ? (string) $titleRaw : '';

        $scoreRaw = $doc['score'] ?? $doc['relevance'] ?? 1.0;
        $score = is_numeric($scoreRaw) ? (float) $scoreRaw : 1.0;

        return [
            'url'     => $url,
            'title'   => $title,
            'domain'  => is_string(parse_url($url, PHP_URL_HOST)) ? (string) parse_url($url, PHP_URL_HOST) : '',
            'passage' => trim((string) preg_replace('/\s+/', ' ', strip_tags($passage))),
            'score'   => $score,
        ];
    }
}
