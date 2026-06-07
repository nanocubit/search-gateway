<?php

declare(strict_types=1);

namespace SearchGateway\Request;

final readonly class SearchRequest
{
    /**
     * @param string $query The user query string.
     * @param list<string> $providers Ordered list of provider names; empty = use route default.
     * @param array<string, mixed> $llm LLM config overrides (driver, model, systemPrompt, temperature, maxTokens, etc.).
     * @param bool $stream When true, controller should emit SSE/WebSocket stream.
     * @param array<string, mixed> $filters Free-form provider filters (language, region, dateRange, etc.).
     * @param list<string> $guardrails List of guardrail names to enforce (resolved by SearchGuardrails).
     * @param array<string, mixed> $userContext Arbitrary context (apiKeyId, userId, scopes, ip, ua, ...).
     * @param array<string, string> $pathParams Path parameters captured by PathMatcher (e.g. :version, :id).
     * @param string|null $apiKeyId Authenticated API key id, if any.
     * @param string $routeName Originating route name (for logging/analytics).
     */
    public function __construct(
        public string $query,
        public array $providers = [],
        public array $llm = [],
        public bool $stream = false,
        public array $filters = [],
        public array $guardrails = [],
        public array $userContext = [],
        public array $pathParams = [],
        public ?string $apiKeyId = null,
        public string $routeName = '',
    ) {
    }

    public function withUserContext(string $key, mixed $value): self
    {
        $ctx = $this->userContext;
        $ctx[$key] = $value;
        return new self(
            query: $this->query,
            providers: $this->providers,
            llm: $this->llm,
            stream: $this->stream,
            filters: $this->filters,
            guardrails: $this->guardrails,
            userContext: $ctx,
            pathParams: $this->pathParams,
            apiKeyId: $this->apiKeyId,
            routeName: $this->routeName,
        );
    }

    public function llmDriver(): ?string
    {
        $d = $this->llm['driver'] ?? null;
        return is_scalar($d) ? (string) $d : null;
    }

    public function llmModel(): ?string
    {
        $m = $this->llm['model'] ?? null;
        return is_scalar($m) ? (string) $m : null;
    }

    /**
     * @param array<string, string> $pathParams
     */
    public function withPathParams(array $pathParams): self
    {
        return new self(
            query: $this->query,
            providers: $this->providers,
            llm: $this->llm,
            stream: $this->stream,
            filters: $this->filters,
            guardrails: $this->guardrails,
            userContext: $this->userContext,
            pathParams: $pathParams,
            apiKeyId: $this->apiKeyId,
            routeName: $this->routeName,
        );
    }
}
