<?php

declare(strict_types=1);

namespace SearchGateway\Portal;

use SearchGateway\Router\Route;
use SearchGateway\Router\RouteRegistryInterface;

final class OpenApiGenerator
{
    public function __construct(
        private readonly RouteRegistryInterface $registry,
        private readonly string $title = 'Search Gateway Universal API',
        private readonly string $version = '1.0.0',
        private readonly string $description = 'Universal AI search gateway with multi-provider support, guardrails, and admin control plane.',
        private readonly string $securitySchemeName = 'bearerAuth',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(): array
    {
        $paths = [];
        foreach ($this->registry->all() as $route) {
            $paths = $this->addRoute($paths, $route);
        }
        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => $this->title,
                'version' => $this->version,
                'description' => $this->description,
            ],
            'servers' => [
                ['url' => '/', 'description' => 'Current host'],
            ],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    $this->securitySchemeName => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'opaque',
                        'description' => 'API key issued via /admin/keys. Prefix: sgw_',
                    ],
                ],
                'schemas' => [
                    'SearchRequest' => [
                        'type' => 'object',
                        'required' => ['query'],
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'User query.'],
                            'providers' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'Ordered list of provider names; empty = use route default.',
                            ],
                            'llm' => [
                                'type' => 'object',
                                'description' => 'LLM overrides (driver, model, systemPrompt, temperature, maxTokens).',
                                'additionalProperties' => true,
                            ],
                            'stream' => ['type' => 'boolean', 'default' => false],
                            'filters' => [
                                'type' => 'object',
                                'description' => 'Free-form provider filters (language, region, dateRange).',
                                'additionalProperties' => true,
                            ],
                            'guardrails' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'Guardrail names to enforce (noPii, noHallucinations, ...).',
                            ],
                        ],
                    ],
                    'SearchResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'action' => ['type' => 'string'],
                            'route' => ['type' => 'string'],
                            'ok' => ['type' => 'boolean'],
                            'status' => ['type' => 'integer'],
                            'payload' => [
                                'oneOf' => [
                                    ['type' => 'object'],
                                    ['type' => 'array', 'items' => ['type' => 'object']],
                                    ['type' => 'null'],
                                ],
                            ],
                            'meta' => ['type' => 'object', 'additionalProperties' => true],
                        ],
                    ],
                    'Error' => [
                        'type' => 'object',
                        'properties' => [
                            'ok' => ['type' => 'boolean', 'enum' => [false]],
                            'error' => ['type' => 'string'],
                        ],
                        'required' => ['ok', 'error'],
                    ],
                ],
            ],
            'security' => [
                [$this->securitySchemeName => []],
            ],
        ];
    }

    /**
     * @return string
     */
    public function toJson(): string
    {
        $encoded = json_encode($this->generate(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $result = is_string($encoded) ? $encoded : '{}';
        return $result;
    }

    /**
     * @param array<string, mixed> $paths
     * @return array<string, mixed>
     */
    private function addRoute(array $paths, Route $route): array
    {
        /** @var array<string, mixed> $pathItem */
        $pathItem = is_array($paths[$route->path] ?? null) ? $paths[$route->path] : [];
        $method = strtolower($route->method);
        $pathItem[$method] = $this->operation($route);
        $paths[$route->path] = $pathItem;
        return $paths;
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(Route $route): array
    {
        $op = [
            'summary' => sprintf('%s via %s', $route->action, $route->name),
            'operationId' => $route->name,
            'description' => sprintf('Action: %s. Config: %s', $route->action, json_encode($route->config, JSON_UNESCAPED_SLASHES)),
            'tags' => [$route->action],
            'responses' => [
                '200' => [
                    'description' => 'Successful response',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/SearchResponse'],
                        ],
                    ],
                ],
                '400' => [
                    'description' => 'Bad request',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/Error'],
                        ],
                    ],
                ],
                '401' => [
                    'description' => 'Unauthorized',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/Error'],
                        ],
                    ],
                ],
                '403' => [
                    'description' => 'Forbidden',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/Error'],
                        ],
                    ],
                ],
                '429' => [
                    'description' => 'Rate limit exceeded',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/Error'],
                        ],
                    ],
                ],
                '500' => [
                    'description' => 'Internal server error',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/Error'],
                        ],
                    ],
                ],
            ],
        ];

        if ($route->requiredScopes !== []) {
            $op['description'] .= "\n\nRequired scopes: " . implode(', ', $route->requiredScopes);
        }
        if ($route->rateLimit !== null) {
            $limit = $route->rateLimit['limit'] ?? 0;
            $window = $route->rateLimit['window'] ?? 0;
            $op['description'] .= sprintf(
                "\n\nRate limit: %d requests per %d seconds",
                $limit,
                $window,
            );
        }

        if (in_array(strtoupper($route->method), ['POST', 'PUT', 'PATCH'], true)) {
            $op['requestBody'] = [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/SearchRequest'],
                    ],
                ],
            ];
        }

        return $op;
    }
}
