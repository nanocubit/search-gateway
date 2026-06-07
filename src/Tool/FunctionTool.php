<?php

declare(strict_types=1);

namespace SearchGateway\Tool;

/**
 * Function calling abstraction для агентов.
 * Любой callable можно обернуть в Tool с JSON Schema.
 */
final class FunctionTool
{
    /**
     * @param callable(array<string, mixed>): mixed $fn
     * @param array<string, mixed> $schema JSON Schema parameters
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        private $fn,
        public readonly array $schema = []
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments): mixed
    {
        return ($this->fn)($arguments);
    }

    /**
     * @return array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}
     */
    public function toOpenAIFunction(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => array_merge(['type' => 'object', 'properties' => new \stdClass()], $this->schema),
            ],
        ];
    }
}
