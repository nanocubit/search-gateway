<?php

declare(strict_types=1);

namespace SearchGateway\Router;

use SearchGateway\Builder\GatewayBuilder;

final class Route
{
    public const METHOD_GET = 'GET';
    public const METHOD_POST = 'POST';
    public const METHOD_PUT = 'PUT';
    public const METHOD_DELETE = 'DELETE';
    public const METHOD_PATCH = 'PATCH';
    public const METHOD_ANY = 'ANY';

    public const ACTION_SEARCH_WEB = 'searchWeb';
    public const ACTION_SEARCH_NEWS = 'searchNews';
    public const ACTION_SEARCH_IMAGES = 'searchImages';
    public const ACTION_SEARCH_GEN = 'searchGen';
    public const ACTION_LLM_CONTEXT = 'llmContext';
    public const ACTION_WORDSTAT = 'wordstat';
    public const ACTION_HYBRID = 'hybrid';

    /** @var GatewayBuilder|callable(): GatewayBuilder|null */
    private $builder;

    /**
     * @param string $name Unique route identifier (e.g. "v1.search.web").
     * @param string $method HTTP method (GET, POST, PUT, DELETE, PATCH, ANY).
     * @param string $path URL path with optional :param placeholders and * wildcard.
     * @param string $action Gateway action: one of ACTION_* constants.
     * @param GatewayBuilder|callable(): GatewayBuilder|null $builder Lazy factory; null = use default builder from controller.
     * @param list<string> $requiredScopes OAuth-style scopes required to invoke this route.
     * @param array{limit?: int, window?: int, key?: string}|null $rateLimit Optional per-route rate limit.
     * @param array<string, mixed> $config Free-form per-route config (passed to plugins and gateway).
     * @param list<callable(object): object> $decorators Middleware-like transforms applied to SearchRequest before dispatch.
     * @param array<string, string> $pathParams Compiled map of named path params (filled by PathMatcher at resolve time).
     */
    public function __construct(
        public string $name,
        public string $method,
        public string $path,
        public string $action,
        GatewayBuilder|callable|null $builder = null,
        public array $requiredScopes = [],
        public ?array $rateLimit = null,
        public array $config = [],
        public array $decorators = [],
        public array $pathParams = [],
    ) {
        $this->builder = $builder;
    }

    /**
     * @param array<string, string> $params
     */
    public function withPathParams(array $params): self
    {
        return new self(
            name: $this->name,
            method: $this->method,
            path: $this->path,
            action: $this->action,
            builder: $this->builder,
            requiredScopes: $this->requiredScopes,
            rateLimit: $this->rateLimit,
            config: $this->config,
            decorators: $this->decorators,
            pathParams: $params,
        );
    }

    public function resolveBuilder(): ?GatewayBuilder
    {
        $builder = $this->builder;
        if ($builder === null) {
            return null;
        }
        if ($builder instanceof GatewayBuilder) {
            return $builder;
        }
        return $builder();
    }

    public function methodMatches(string $httpMethod): bool
    {
        if ($this->method === self::METHOD_ANY) {
            return true;
        }
        return strcasecmp($this->method, $httpMethod) === 0;
    }
}
