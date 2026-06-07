<?php

declare(strict_types=1);

namespace SearchGateway\Tool;

/**
 * Registry of tools for agent. Auto-discovery compatible.
 */
final class ToolRegistry
{
    /** @var array<string, FunctionTool> */
    private array $tools = [];

    public function register(FunctionTool $tool): self
    {
        $this->tools[$tool->name] = $tool;
        return $this;
    }

    public function get(string $name): ?FunctionTool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return list<FunctionTool>
     */
    public function getAll(): array
    {
        return array_values($this->tools);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toOpenAIFunctions(): array
    {
        return array_values(array_map(
            static fn(FunctionTool $t): array => $t->toOpenAIFunction(),
            $this->tools
        ));
    }
}
