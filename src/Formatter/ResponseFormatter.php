<?php

declare(strict_types=1);

namespace SearchGateway\Formatter;

use SearchGateway\Contract\GenerativeSearchResultDTO;

/**
 * Formats generative search results into structured outputs:
 * markdown, JSON, HTML, or custom templates.
 */
final class ResponseFormatter
{
    /**
     * Convert DTO to markdown with citations.
     */
    public function toMarkdown(GenerativeSearchResultDTO $dto): string
    {
        $lines = [];
        $lines[] = $dto->answer;
        $lines[] = '';
        $lines[] = '---';
        $lines[] = '**Sources:**';

        foreach ($dto->sources as $i => $src) {
            $n = $i + 1;
            $title = is_scalar($src['title'] ?? null) ? (string) $src['title'] : 'Untitled';
            $url = is_scalar($src['url'] ?? null) ? (string) $src['url'] : '';
            $lines[] = "{$n}. [{$title}]({$url})";
        }

        return implode("\n", $lines);
    }

    /**
     * Convert DTO to structured JSON.
     */
    public function toJson(GenerativeSearchResultDTO $dto): string
    {
        $encoded = json_encode([
            'answer' => $dto->answer,
            'sources' => $dto->sources,
            'meta' => $dto->meta,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded === false ? '{}' : $encoded;
    }

    /**
     * Convert DTO to HTML snippet.
     */
    public function toHtml(GenerativeSearchResultDTO $dto): string
    {
        $answer = htmlspecialchars($dto->answer);
        $html = '<div class="search-answer">' . nl2br($answer) . '</div>';
        $html .= '<ul class="search-sources">';
        foreach ($dto->sources as $src) {
            $title = htmlspecialchars(is_scalar($src['title'] ?? null) ? (string) $src['title'] : 'Untitled');
            $url = htmlspecialchars(is_scalar($src['url'] ?? null) ? (string) $src['url'] : '');
            $html .= "<li><a href=\"{$url}\" target=\"_blank\">{$title}</a></li>";
        }
        $html .= '</ul>';
        return $html;
    }

    /**
     * Custom template with placeholders: {answer}, {sources}, {meta}.
     */
    public function toTemplate(GenerativeSearchResultDTO $dto, string $template): string
    {
        $lines = [];
        $i = 0;
        foreach ($dto->sources as $s) {
            $i++;
            $title = is_scalar($s['title'] ?? null) ? (string) $s['title'] : '';
            $url = is_scalar($s['url'] ?? null) ? (string) $s['url'] : '';
            $lines[] = "{$i}. {$title} — {$url}";
        }
        $sourcesText = implode("\n", $lines);

        $metaJson = json_encode($dto->meta, JSON_UNESCAPED_UNICODE);

        return str_replace(
            ['{answer}', '{sources}', '{meta}'],
            [$dto->answer, $sourcesText, $metaJson === false ? '' : $metaJson],
            $template
        );
    }
}
