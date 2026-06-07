<?php

declare(strict_types=1);

namespace SearchGateway\Request;

use SearchGateway\Contract\GenerativeSearchResultDTO;

final readonly class SearchResponse
{
    /**
     * @param string $action Echoes the route action (searchWeb, searchGen, llmContext, hybrid).
     * @param string $routeName Originating route name.
     * @param GenerativeSearchResultDTO|list<array<string, mixed>>|array<string, mixed>|null $payload Discriminated by action.
     * @param array<string, mixed> $meta Latency, provider, tokens, guardrail report, etc.
     * @param int $status HTTP status code (200, 400, 401, 403, 429, 500).
     */
    public function __construct(
        public string $action,
        public string $routeName,
        public GenerativeSearchResultDTO|array|null $payload = null,
        public array $meta = [],
        public int $status = 200,
    ) {
    }

    /**
     * @param list<array<string, mixed>>|array<string, mixed> $payload
     * @param array<string, mixed> $meta
     */
    public static function ok(string $action, string $routeName, GenerativeSearchResultDTO|array $payload, array $meta = []): self
    {
        return new self(
            action: $action,
            routeName: $routeName,
            payload: $payload,
            meta: $meta + ['ok' => true],
            status: 200,
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function error(string $action, string $routeName, string $message, int $status, array $meta = []): self
    {
        return new self(
            action: $action,
            routeName: $routeName,
            payload: null,
            meta: $meta + ['ok' => false, 'error' => $message],
            status: $status,
        );
    }

    public function isOk(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function withMeta(array $meta): self
    {
        return new self(
            action: $this->action,
            routeName: $this->routeName,
            payload: $this->payload,
            meta: $meta + $this->meta,
            status: $this->status,
        );
    }

    public function withMetaValue(string $key, mixed $value): self
    {
        $meta = $this->meta;
        $meta[$key] = $value;
        return new self(
            action: $this->action,
            routeName: $this->routeName,
            payload: $this->payload,
            meta: $meta,
            status: $this->status,
        );
    }

    public function withStatus(int $status): self
    {
        return new self(
            action: $this->action,
            routeName: $this->routeName,
            payload: $this->payload,
            meta: $this->meta,
            status: $status,
        );
    }

    /**
     * @return array{action: string, route: string, ok: bool, status: int, payload: GenerativeSearchResultDTO|list<array<string, mixed>>|array<string, mixed>|null, meta: array<string, mixed>}
     */
    public function toArray(): array
    {
        $serialised = $this->payload;
        if ($serialised instanceof GenerativeSearchResultDTO) {
            $serialised = [
                'answer' => $serialised->answer,
                'sources' => $serialised->sources,
            ];
        }
        return [
            'action' => $this->action,
            'route' => $this->routeName,
            'ok' => $this->isOk(),
            'status' => $this->status,
            'payload' => $serialised,
            'meta' => $this->meta,
        ];
    }
}
