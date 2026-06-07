<?php

declare(strict_types=1);

namespace SearchGateway\Prompt;

/**
 * Structured prompt builder for RAG / search-augmented LLM queries.
 * Supports multiple output formats and citation styles.
 */
final class PromptBuilder
{
    private string $system = 'You are an AI search assistant. Use the provided sources to answer. Cite sources by number.';
    private string $task = '';
    /** @var list<array<string, mixed>> */
    private array $sources = [];
    private string $format = 'markdown';
    private bool $citations = true;
    private ?string $tone = null;
    private ?int $maxWords = null;

    public function system(string $system): self
    {
        $this->system = $system;
        return $this;
    }

    public function task(string $task): self
    {
        $this->task = $task;
        return $this;
    }

    /**
     * @param list<array<string, mixed>> $sources
     */
    public function sources(array $sources): self
    {
        $this->sources = $sources;
        return $this;
    }

    public function format(string $format): self
    {
        $this->format = $format;
        return $this;
    }

    public function citations(bool $enabled): self
    {
        $this->citations = $enabled;
        return $this;
    }

    public function tone(string $tone): self
    {
        $this->tone = $tone;
        return $this;
    }

    public function maxWords(int $n): self
    {
        $this->maxWords = $n;
        return $this;
    }

    public function build(): string
    {
        $parts = [];
        $parts[] = $this->system;

        if ($this->tone !== null) {
            $parts[] = "Tone: {$this->tone}";
        }
        if ($this->maxWords !== null) {
            $parts[] = "Limit your answer to {$this->maxWords} words.";
        }

        $parts[] = "Output format: {$this->format}";
        $parts[] = "Task:\n{$this->task}";

        if ($this->sources !== []) {
            $sourceText = $this->citations
                ? $this->formatCitations()
                : $this->formatPlain();
            $parts[] = "Sources:\n{$sourceText}";
        }

        return implode("\n\n", $parts);
    }

    private function formatCitations(): string
    {
        $lines = [];
        $i = 0;
        foreach ($this->sources as $doc) {
            $i++;
            $title = is_scalar($doc['title'] ?? null) ? (string) $doc['title'] : '';
            $url = is_scalar($doc['url'] ?? null) ? (string) $doc['url'] : '';
            $passage = is_scalar($doc['passage'] ?? null) ? (string) $doc['passage'] : '';
            $lines[] = "[{$i}] {$title}\nURL: {$url}\n{$passage}";
        }
        return implode("\n\n", $lines);
    }

    private function formatPlain(): string
    {
        $lines = [];
        foreach ($this->sources as $doc) {
            $title = is_scalar($doc['title'] ?? null) ? (string) $doc['title'] : '';
            $url = is_scalar($doc['url'] ?? null) ? (string) $doc['url'] : '';
            $passage = is_scalar($doc['passage'] ?? null) ? (string) $doc['passage'] : '';
            $lines[] = "Title: {$title}\nURL: {$url}\nPassage: {$passage}";
        }
        return implode("\n\n", $lines);
    }
}
