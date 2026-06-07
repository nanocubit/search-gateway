<?php

declare(strict_types=1);

namespace SearchGateway\Streaming;

use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Contract\StreamingLLMClientInterface;

/**
 * Perplexity-style streaming generative search.
 * Yields answer chunks while collecting sources in parallel.
 */
final class StreamingSearchGateway implements SearchGatewayInterface
{
    public function __construct(
        private SearchGatewayInterface $inner,
        private StreamingLLMClientInterface $llm
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchWeb(string $query, array $options = []): array
    {
        return $this->inner->searchWeb($query, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchNews(string $query, array $options = []): array
    {
        return $this->inner->searchNews($query, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchImages(string $query, array $options = []): array
    {
        return $this->inner->searchImages($query, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function searchGen(string $query, array $options = []): GenerativeSearchResultDTO
    {
        return $this->inner->searchGen($query, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int|string, mixed>
     */
    public function wordstat(string $query, array $options = []): array
    {
        return $this->inner->wordstat($query, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array{url: string, title: string, domain: string, passage: string, score: float}>
     */
    public function llmContext(string $query, array $options = []): array
    {
        return $this->inner->llmContext($query, $options);
    }

    /**
     * Stream a generative answer. Returns a generator that yields text chunks.
     *
     * Example:
     *   $gen = $streamer->streamGen('...');
     *   foreach ($gen as $chunk) { echo $chunk; }
     *   $dto = $gen->getReturn();
     *
     * @param array<string, mixed> $options
     * @return \Generator<int, string, mixed, GenerativeSearchResultDTO>
     */
    public function streamGen(string $query, array $options = []): \Generator
    {
        $sources = $this->inner->llmContext($query, $options);
        $prompt = $this->buildPrompt($query, $sources);

        $llmOptionsRaw = $options['llm'] ?? [];
        /** @var array<string, mixed> $llmOptions */
        $llmOptions = is_array($llmOptionsRaw) ? $llmOptionsRaw : [];

        $buffer = '';
        foreach ($this->llm->streamGenerate($prompt, $llmOptions) as $chunk) {
            $buffer .= $chunk;
            yield $chunk;
        }

        return new GenerativeSearchResultDTO(
            answer: $buffer,
            sources: $sources,
            meta: ['provider' => 'streaming', 'streamed' => true]
        );
    }

    /**
     * @param list<array<string, mixed>> $sources
     */
    private function buildPrompt(string $query, array $sources): string
    {
        $sourceText = implode("\n\n", array_map(
            static function (array $doc, int $i): string {
                $titleRaw = $doc['title'] ?? '';
                $passageRaw = $doc['passage'] ?? '';
                $title = is_scalar($titleRaw) ? (string) $titleRaw : '';
                $passage = is_scalar($passageRaw) ? (string) $passageRaw : '';
                return sprintf("[%d] %s\n%s", $i + 1, $title, $passage);
            },
            $sources,
            array_keys($sources)
        ));

        return <<<PROMPT
You are an AI search assistant. Answer the question using the provided sources. Cite sources by number.

Question: {$query}

Sources:
{$sourceText}

Answer (streamed):
PROMPT;
    }
}
