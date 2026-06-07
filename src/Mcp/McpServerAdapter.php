<?php

declare(strict_types=1);

namespace SearchGateway\Mcp;

use SearchGateway\Tool\FunctionTool;
use SearchGateway\Tool\SearchTool;
use SearchGateway\Tool\ToolRegistry;

/**
 * Model Context Protocol (MCP) adapter.
 * Экспортирует SearchTool как MCP tools для Claude Desktop, Cursor, и других MCP-клиентов.
 * Идея из claude-php-agent: стандартизированный протокол взаимодействия AI-инструментов.
 */
final class McpServerAdapter
{
    private ToolRegistry $registry;

    public function __construct(private SearchTool $searchTool)
    {
        $this->registry = new ToolRegistry();
        $this->registerSearchTools();
    }

    private function registerSearchTools(): void
    {
        $this->registry->register(new FunctionTool(
            name: 'search_web',
            description: 'Search the web for current information. Returns list of results with title, URL, and passage.',
            fn: function (array $args): array {
                /** @var array<string, mixed> $opts */
                $opts = is_array($args['options'] ?? null) ? $args['options'] : [];
                return $this->searchTool->web($this->strArg($args, 'query'), $opts);
            },
            schema: [
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Search query'],
                    'options' => ['type' => 'object', 'description' => 'Optional search parameters'],
                ],
                'required' => ['query'],
            ]
        ));

        $this->registry->register(new FunctionTool(
            name: 'search_context',
            description: 'Get LLM-ready context chunks for RAG. Returns clean passages with URLs.',
            fn: function (array $args): array {
                /** @var array<string, mixed> $opts */
                $opts = is_array($args['options'] ?? null) ? $args['options'] : [];
                return $this->searchTool->context($this->strArg($args, 'query'), $opts);
            },
            schema: [
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Query to get context for'],
                    'options' => ['type' => 'object'],
                ],
                'required' => ['query'],
            ]
        ));

        $this->registry->register(new FunctionTool(
            name: 'search_images',
            description: 'Search for images. Returns list of image results.',
            fn: function (array $args): array {
                /** @var array<string, mixed> $opts */
                $opts = is_array($args['options'] ?? null) ? $args['options'] : [];
                return $this->searchTool->images($this->strArg($args, 'query'), $opts);
            },
            schema: [
                'properties' => [
                    'query' => ['type' => 'string'],
                ],
                'required' => ['query'],
            ]
        ));
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function handle(array $request): array
    {
        $method = is_scalar($request['method'] ?? null) ? (string) $request['method'] : '';

        return match ($method) {
            'tools/list' => [
                'jsonrpc' => '2.0',
                'id' => $request['id'] ?? null,
                'result' => ['tools' => $this->registry->toOpenAIFunctions()],
            ],
            'tools/call' => $this->callTool(is_array($request['params'] ?? null) ? $request['params'] : []),
            default => [
                'jsonrpc' => '2.0',
                'id' => $request['id'] ?? null,
                'error' => ['code' => -32601, 'message' => 'Method not found'],
            ],
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function callTool(array $params): array
    {
        $name = is_scalar($params['name'] ?? null) ? (string) $params['name'] : '';
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        $tool = $this->registry->get($name);
        if ($tool === null) {
            return [
                'jsonrpc' => '2.0',
                'error' => ['code' => -32602, 'message' => "Unknown tool: {$name}"],
            ];
        }

        try {
            $result = $tool->execute($arguments);
            return [
                'jsonrpc' => '2.0',
                'result' => ['content' => [['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_UNICODE)]]],
            ];
        } catch (\Throwable $e) {
            return [
                'jsonrpc' => '2.0',
                'error' => ['code' => -32603, 'message' => $e->getMessage()],
            ];
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    private function strArg(array $args, string $key): string
    {
        $value = $args[$key] ?? '';
        return is_scalar($value) ? (string) $value : '';
    }
}
