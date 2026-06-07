<?php

declare(strict_types=1);

namespace SearchGateway\Agent;

use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Infrastructure\LLMClientInterface;
use SearchGateway\Tool\FunctionTool;
use SearchGateway\Tool\ToolRegistry;

/**
 * ReAct (Reasoning + Acting) agent.
 * LLM думает вслух: Thought -> Action -> Observation -> ... -> Answer.
 * Идея из LangChain ReAct: явное reasoning улучшает точность сложных задач.
 */
final class ReActAgent
{
    private ToolRegistry $tools;

    public function __construct(
        private LLMClientInterface $llm,
        private SearchGatewayInterface $search,
        private int $maxIterations = 5
    ) {
        $this->tools = new ToolRegistry();
        $this->registerDefaultTools();
    }

    public function registerTool(FunctionTool $tool): self
    {
        $this->tools->register($tool);
        return $this;
    }

    /**
     * @return array{
     *   answer: string,
     *   thoughts: list<string>,
     *   actions: list<array<string, mixed>>,
     *   observations: list<string>,
     *   iterations: int
     * }
     */
    public function run(string $task): array
    {
        $thoughts = [];
        $actions = [];
        $observations = [];

        for ($i = 0; $i < $this->maxIterations; $i++) {
            $prompt = $this->buildReActPrompt($task, $thoughts, $actions, $observations);
            $response = $this->llm->generate($prompt);

            $parsed = $this->parseReAct($response);
            $thoughtRaw = $parsed['thought'] ?? '';
            $thoughts[] = is_scalar($thoughtRaw) ? (string) $thoughtRaw : '';

            if (isset($parsed['answer'])) {
                $answerRaw = $parsed['answer'];
                $answer = is_scalar($answerRaw) ? (string) $answerRaw : '';
                return [
                    'answer' => $answer,
                    'thoughts' => $thoughts,
                    'actions' => $actions,
                    'observations' => $observations,
                    'iterations' => $i + 1,
                ];
            }

            if (isset($parsed['action']) && is_array($parsed['action'])) {
                $actions[] = $parsed['action'];
                $obs = $this->executeAction($parsed['action']);
                $observations[] = $obs;
            }
        }

        return [
            'answer' => 'Failed to reach conclusion within ' . $this->maxIterations . ' iterations.',
            'thoughts' => $thoughts,
            'actions' => $actions,
            'observations' => $observations,
            'iterations' => $this->maxIterations,
        ];
    }

    private function registerDefaultTools(): void
    {
        $this->tools->register(new FunctionTool(
            name: 'search',
            description: 'Search the web for information.',
            fn: function (array $args): array {
                $query = is_scalar($args['query'] ?? null) ? (string) $args['query'] : '';
                return $this->search->llmContext($query, ['docsOnPage' => 5]);
            },
            schema: ['properties' => ['query' => ['type' => 'string']], 'required' => ['query']]
        ));
    }

    /**
     * @param array<string, mixed> $action
     */
    private function executeAction(array $action): string
    {
        $toolName = is_scalar($action['tool'] ?? null) ? (string) $action['tool'] : '';
        $tool = $this->tools->get($toolName);
        if ($tool === null) {
            return "Error: tool '{$toolName}' not found";
        }
        $args = is_array($action['args'] ?? null) ? $action['args'] : [];
        try {
            $result = $tool->execute($args);
            $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            return $encoded === false ? 'Error: failed to encode result' : $encoded;
        } catch (\Throwable $e) {
            return "Error: {$e->getMessage()}";
        }
    }

    /**
     * @param list<string> $thoughts
     * @param list<array<string, mixed>> $actions
     * @param list<string> $observations
     */
    private function buildReActPrompt(string $task, array $thoughts, array $actions, array $observations): string
    {
        $history = '';
        foreach ($thoughts as $i => $thought) {
            $history .= "Thought {$i}: {$thought}\n";
            if (isset($actions[$i])) {
                $actionStr = json_encode($actions[$i]);
                $history .= "Action {$i}: " . ($actionStr === false ? '{}' : $actionStr) . "\n";
                $obs = $observations[$i] ?? '';
                $history .= "Observation {$i}: {$obs}\n";
            }
        }

        $toolsList = [];
        foreach ($this->tools->getAll() as $tool) {
            $toolsList[] = $tool;
        }
        $toolsDesc = implode("\n", array_map(
            static fn(FunctionTool $t): string => "- {$t->name}: {$t->description}",
            $toolsList
        ));

        return <<<PROMPT
You are a ReAct agent. Solve the task by thinking step by step.
You have access to these tools:
{$toolsDesc}

Respond in EXACTLY this format:
Thought: <your reasoning>
Action: {"tool": "<tool_name>", "args": {"<param>": "<value>"}}
OR
Thought: <your reasoning>
Answer: <final answer>

Task: {$task}

{$history}
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseReAct(string $raw): array
    {
        $result = [];
        if (
            preg_match('/Thought:\s*(.+?)(?=
Action:|
Answer:|$)/s', $raw, $m)
        ) {
            $result['thought'] = trim($m[1]);
        }
        if (preg_match('/Answer:\s*(.+)/s', $raw, $m)) {
            $result['answer'] = trim($m[1]);
        }
        if (preg_match('/Action:\s*(\{.+\})/s', $raw, $m)) {
            $decoded = json_decode($m[1], true);
            $result['action'] = is_array($decoded) ? $decoded : [];
        }
        return $result;
    }
}
