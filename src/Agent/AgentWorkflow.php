<?php

declare(strict_types=1);

namespace SearchGateway\Agent;

use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Infrastructure\LLMClientInterface;
use SearchGateway\Tool\SearchTool;

/**
 * Comet / DIA-style agent workflow.
 * Orchestrates search + LLM reasoning in discrete steps.
 */
final class AgentWorkflow
{
    /** @var list<callable(array<string, mixed>, self): array<string, mixed>> */
    private array $steps = [];

    public function __construct(
        private SearchTool $searchTool,
        private LLMClientInterface $llm,
        private ?PersonalSearchContext $personalContext = null
    ) {
    }

    /**
     * Add a custom step to the pipeline.
     *
     * @param callable(array<string, mixed>, self): array<string, mixed> $step
     */
    public function addStep(callable $step): self
    {
        $this->steps[] = $step;
        return $this;
    }

    /**
     * Run the workflow on a task.
     */
    public function run(string $task): string
    {
        $query = $this->personalContext?->enrich($task) ?? $task;

        $context = [
            'task' => $task,
            'enriched_query' => $query,
            'search' => $this->searchTool->context($query),
        ];

        foreach ($this->steps as $step) {
            $context = $step($context, $this);
        }

        $prompt = $this->buildPrompt($context, $task);
        $answer = $this->llm->generate($prompt);

        $searchResults = $context['search'] ?? [];
        if (is_array($searchResults)) {
            /** @var list<array<string, mixed>> $searchList */
            $searchList = array_values(array_filter($searchResults, 'is_array'));
            $this->personalContext?->record($task, $searchList);
        }

        return $answer;
    }

    /**
     * Direct generative search (one-shot, no custom steps).
     */
    public function gen(string $task): GenerativeSearchResultDTO
    {
        $query = $this->personalContext?->enrich($task) ?? $task;
        $ctx = $this->searchTool->context($query);
        $prompt = $this->buildPrompt(['search' => $ctx, 'task' => $task], $task);
        $answer = $this->llm->generate($prompt);

        $this->personalContext?->record($task, $ctx);

        return new GenerativeSearchResultDTO(
            answer: $answer,
            sources: $ctx,
            meta: ['workflow' => 'one_shot']
        );
    }

    public function searchTool(): SearchTool
    {
        return $this->searchTool;
    }

    public function llm(): LLMClientInterface
    {
        return $this->llm;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function buildPrompt(array $context, string $task): string
    {
        $sources = is_array($context['search'] ?? null) ? $context['search'] : [];
        $extra = is_scalar($context['extra'] ?? null) ? (string) $context['extra'] : '';

        $lines = [];
        foreach ($sources as $doc) {
            if (!is_array($doc)) {
                continue;
            }
            $title = is_scalar($doc['title'] ?? null) ? (string) $doc['title'] : '';
            $url = is_scalar($doc['url'] ?? null) ? (string) $doc['url'] : '';
            $passage = is_scalar($doc['passage'] ?? null) ? (string) $doc['passage'] : '';
            $lines[] = "Title: {$title}\nURL: {$url}\nPassage: {$passage}";
        }
        $text = implode("\n\n", $lines);

        $extraBlock = $extra !== '' ? "\n\nAdditional context:\n{$extra}" : '';

        return <<<PROMPT
You are an AI search assistant with access to real-time sources.
Answer the task using the provided sources. Cite the most relevant sources by URL.
Be concise, factual, and indicate uncertainty if sources are insufficient.

Task:
{$task}{$extraBlock}

Sources:
{$text}
PROMPT;
    }
}
