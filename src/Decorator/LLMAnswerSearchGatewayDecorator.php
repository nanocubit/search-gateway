<?php

declare(strict_types=1);

namespace SearchGateway\Decorator;

use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Infrastructure\LLMClientInterface;

/**
 * Atlas / Comet-style decorator: synthesise an LLM answer on top of llmContext.
 * If the inner gateway already returns an answer (e.g. Perplexity), it is preserved.
 */
final class LLMAnswerSearchGatewayDecorator implements SearchGatewayInterface
{
    public function __construct(
        private SearchGatewayInterface $inner,
        private LLMClientInterface $llm,
        private ?string $systemPrompt = null
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
        $innerResult = $this->inner->searchGen($query, $options);

        // If inner already generated an answer, forward as-is.
        if (trim($innerResult->answer) !== '') {
            return $innerResult;
        }

        $prompt = $this->buildPrompt($query, $innerResult->sources);
        $llmOptionsRaw = $options['llm'] ?? [];
        /** @var array<string, mixed> $llmOptions */
        $llmOptions = is_array($llmOptionsRaw) ? $llmOptionsRaw : [];
        $answer = $this->llm->generate($prompt, $llmOptions);

        return new GenerativeSearchResultDTO(
            answer: $answer,
            sources: $innerResult->sources,
            meta: array_merge($innerResult->meta, ['synthesised_answer' => true])
        );
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
     * @return list<array<string, mixed>>
     */
    public function llmContext(string $query, array $options = []): array
    {
        return $this->inner->llmContext($query, $options);
    }

    /**
     * @param list<array<string, mixed>> $sources
     */
    private function buildPrompt(string $query, array $sources): string
    {
        $system = $this->systemPrompt
            ?? 'You are an AI search assistant. Answer the user question using only the provided sources. Cite sources by number.';

        $sourceText = implode("

", array_map(
            static function (array $doc, int $i): string {
                $titleRaw = $doc['title'] ?? '';
                $urlRaw = $doc['url'] ?? '';
                $passageRaw = $doc['passage'] ?? '';
                $title = is_scalar($titleRaw) ? (string) $titleRaw : '';
                $url = is_scalar($urlRaw) ? (string) $urlRaw : '';
                $passage = is_scalar($passageRaw) ? (string) $passageRaw : '';
                return sprintf(
                    "[%d] %s
URL: %s
%s",
                    $i + 1,
                    $title,
                    $url,
                    $passage
                );
            },
            $sources,
            array_keys($sources)
        ));

        return <<<PROMPT
{$system}

Question: {$query}

Sources:
{$sourceText}

Answer:
PROMPT;
    }
}
