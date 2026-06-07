<?php

declare(strict_types=1);

namespace SearchGateway\Chunking;

/**
 * Semantic document chunking strategies.
 * Recursive, fixed-size, paragraph-based, sentence-based.
 */
final class DocumentSplitter
{
    /**
     * @param array<string, mixed> $options
     * @return list<array{id: string, text: string, meta: array<string, mixed>}>
     */
    public function split(string $text, array $options = []): array
    {
        $strategy = is_string($options['strategy'] ?? null) ? $options['strategy'] : 'recursive';
        $chunkSize = is_int($options['chunk_size'] ?? null) ? $options['chunk_size'] : 500;
        $overlap = is_int($options['overlap'] ?? null) ? $options['overlap'] : 50;

        return match ($strategy) {
            'fixed' => $this->fixed($text, $chunkSize, $overlap),
            'sentence' => $this->sentence($text, $chunkSize, $overlap),
            'paragraph' => $this->paragraph($text, $chunkSize, $overlap),
            'recursive' => $this->recursive($text, $chunkSize, $overlap),
            default => $this->recursive($text, $chunkSize, $overlap),
        };
    }

    /**
     * @return list<array{id: string, text: string, meta: array<string, mixed>}>
     */
    private function fixed(string $text, int $size, int $overlap): array
    {
        $chunks = [];
        $len = mb_strlen($text);
        $step = max(1, $size - $overlap);
        $idx = 0;
        for ($i = 0; $i < $len; $i += $step) {
            $chunk = mb_substr($text, $i, $size);
            $chunks[] = [
                'id' => 'chunk_' . $idx,
                'text' => $chunk,
                'meta' => ['start' => $i, 'end' => min($i + $size, $len)],
            ];
            $idx++;
        }
        return $chunks;
    }

    /**
     * @return list<array{id: string, text: string, meta: array<string, mixed>}>
     */
    private function sentence(string $text, int $size, int $overlap): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return $this->mergeUnits($sentences, $size, $overlap, 'sent');
    }

    /**
     * @return list<array{id: string, text: string, meta: array<string, mixed>}>
     */
    private function paragraph(string $text, int $size, int $overlap): array
    {
        $paragraphs = preg_split('/
\s*
/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return $this->mergeUnits($paragraphs, $size, $overlap, 'par');
    }

    /**
     * Recursive: paragraph -> sentence -> fixed fallback.
     *
     * @return list<array{id: string, text: string, meta: array<string, mixed>}>
     */
    private function recursive(string $text, int $size, int $overlap): array
    {
        $paragraphs = preg_split('/
\s*
/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $chunks = [];
        $buffer = '';
        $idx = 0;

        foreach ($paragraphs as $par) {
            if (mb_strlen($par) > $size) {
                // Flush buffer first
                if ($buffer !== '') {
                    $chunks[] = ['id' => 'chunk_' . $idx++, 'text' => $buffer, 'meta' => []];
                    $buffer = '';
                }
                // Split oversized paragraph by sentences
                $sentences = preg_split('/(?<=[.!?])\s+/', $par, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                foreach ($sentences as $sent) {
                    if (mb_strlen($buffer . ' ' . $sent) > $size) {
                        if ($buffer !== '') {
                            $chunks[] = ['id' => 'chunk_' . $idx++, 'text' => $buffer, 'meta' => []];
                        }
                        $buffer = $sent;
                    } else {
                        $buffer = $buffer === '' ? $sent : $buffer . ' ' . $sent;
                    }
                }
            } else {
                if (
                    mb_strlen($buffer . "

" . $par) > $size
                ) {
                    $chunks[] = ['id' => 'chunk_' . $idx++, 'text' => $buffer, 'meta' => []];
                    $buffer = $par;
                } else {
                    $buffer = $buffer === '' ? $par : $buffer . "

" . $par;
                }
            }
        }

        if ($buffer !== '') {
            $chunks[] = ['id' => 'chunk_' . $idx++, 'text' => $buffer, 'meta' => []];
        }

        // Apply overlap
        return $this->applyOverlap($chunks, $overlap);
    }

    /**
     * @param list<string> $units
     * @return list<array{id: string, text: string, meta: array<string, mixed>}>
     */
    private function mergeUnits(array $units, int $size, int $overlap, string $prefix): array
    {
        $chunks = [];
        $buffer = '';
        $idx = 0;
        foreach ($units as $unit) {
            if (mb_strlen($buffer . ' ' . $unit) > $size) {
                $chunks[] = ['id' => "{$prefix}_{$idx}", 'text' => $buffer, 'meta' => []];
                $idx++;
                $buffer = $unit;
            } else {
                $buffer = $buffer === '' ? $unit : $buffer . ' ' . $unit;
            }
        }
        if ($buffer !== '') {
            $chunks[] = ['id' => "{$prefix}_{$idx}", 'text' => $buffer, 'meta' => []];
        }
        return $this->applyOverlap($chunks, $overlap);
    }

    /**
     * @param list<array{id: string, text: string, meta: array<string, mixed>}> $chunks
     * @return list<array{id: string, text: string, meta: array<string, mixed>}>
     */
    private function applyOverlap(array $chunks, int $overlap): array
    {
        if ($overlap <= 0 || count($chunks) < 2) {
            return $chunks;
        }
        $result = [$chunks[0]];
        for ($i = 1; $i < count($chunks); $i++) {
            $prevText = $chunks[$i - 1]['text'];
            $overlapText = mb_substr($prevText, max(0, mb_strlen($prevText) - $overlap));
            $result[] = [
                'id' => $chunks[$i]['id'],
                'text' => $overlapText . ' ' . $chunks[$i]['text'],
                'meta' => array_merge($chunks[$i]['meta'], ['overlap_from_prev' => true]),
            ];
        }
        return $result;
    }
}
