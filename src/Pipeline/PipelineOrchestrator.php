<?php

declare(strict_types=1);

namespace SearchGateway\Pipeline;

/**
 * DAG-based pipeline orchestrator.
 * Определяет зависимости между шагами: search -> chunk -> embed -> store -> query.
 * Идея из Prefect: явное управление workflow.
 */
final class PipelineOrchestrator
{
    /** @var array<string, array{fn: callable, deps: list<string>}> */
    private array $steps = [];
    /** @var array<string, mixed> */
    private array $results = [];

    /**
     * @param list<string> $dependencies
     */
    public function add(string $name, callable $fn, array $dependencies = []): self
    {
        $this->steps[$name] = ['fn' => $fn, 'deps' => $dependencies];
        return $this;
    }

    /**
     * Execute DAG in topological order.
     *
     * @param array<string, mixed> $initialData
     * @return array<string, mixed>
     */
    public function execute(array $initialData = []): array
    {
        $this->results = $initialData;
        $executed = [];
        $pending = array_keys($this->steps);

        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $i => $name) {
                $step = $this->steps[$name];
                if ($this->dependenciesMet($step['deps'], $executed)) {
                    $this->results[$name] = ($step['fn'])($this->results);
                    $executed[] = $name;
                    unset($pending[$i]);
                    $progress = true;
                }
            }
            if (!$progress) {
                throw new \RuntimeException('Circular dependency detected in pipeline');
            }
        }

        return $this->results;
    }

    /**
     * @param list<string> $deps
     * @param list<string> $executed
     */
    private function dependenciesMet(array $deps, array $executed): bool
    {
        foreach ($deps as $dep) {
            if (!in_array($dep, $executed, true)) {
                return false;
            }
        }
        return true;
    }
}
