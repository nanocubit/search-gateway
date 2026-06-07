<?php

declare(strict_types=1);

namespace SearchGateway\Router;

use Psr\Http\Message\ServerRequestInterface;
use SearchGateway\Contract\SearchGatewayException;
use SearchGateway\Request\SearchRequest;

final class RouteResolver
{
    /**
     * Convert a PSR-7 ServerRequest into a SearchRequest, using the matched route.
     *
     * @param array<string, mixed>|null $body Already-parsed JSON body (caller's responsibility).
     */
    public function resolve(Route $route, ServerRequestInterface $request, ?array $body = null): SearchRequest
    {
        $body ??= $this->parseJsonBody($request);

        $queryRaw = $body['query'] ?? null;
        if (!is_string($queryRaw) || $queryRaw === '') {
            $queryParam = $request->getQueryParams()['q'] ?? null;
            $queryRaw = is_string($queryParam) ? $queryParam : '';
        }
        if ($queryRaw === '') {
            throw new SearchGatewayException('Missing required field: query');
        }

        $providersRaw = $body['providers'] ?? [];
        $providers = is_array($providersRaw) ? array_values(array_filter($providersRaw, 'is_string')) : [];

        $llmRaw = $body['llm'] ?? [];
        $llm = is_array($llmRaw) ? $llmRaw : [];

        $streamRaw = $body['stream'] ?? false;
        $stream = is_bool($streamRaw) ? $streamRaw : false;

        $filtersRaw = $body['filters'] ?? [];
        $filters = is_array($filtersRaw) ? $filtersRaw : [];

        $guardrailsRaw = $body['guardrails'] ?? [];
        $guardrails = is_array($guardrailsRaw) ? array_values(array_filter($guardrailsRaw, 'is_string')) : [];

        $userContextRaw = $body['userContext'] ?? [];
        $userContext = is_array($userContextRaw) ? $userContextRaw : [];

        $apiKeyIdRaw = $body['apiKeyId'] ?? null;
        $apiKeyId = is_string($apiKeyIdRaw) ? $apiKeyIdRaw : null;

        $userContext = $this->mergeRequestContext($request, $userContext);

        return new SearchRequest(
            query: $queryRaw,
            providers: $providers,
            llm: $llm,
            stream: $stream,
            filters: $filters,
            guardrails: $guardrails,
            userContext: $userContext,
            pathParams: $route->pathParams,
            apiKeyId: $apiKeyId,
            routeName: $route->name,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonBody(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            return $parsed;
        }
        $body = (string) $request->getBody();
        if ($body === '') {
            return [];
        }
        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $userContext
     * @return array<string, mixed>
     */
    private function mergeRequestContext(ServerRequestInterface $request, array $userContext): array
    {
        $userContext['ip'] ??= $this->clientIp($request);
        $userContext['ua'] ??= $request->getHeaderLine('User-Agent');
        $userContext['method'] ??= $request->getMethod();
        return $userContext;
    }

    private function clientIp(ServerRequestInterface $request): string
    {
        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0] ?? '');
            if ($first !== '') {
                return $first;
            }
        }
        $server = $request->getServerParams();
        $remote = $server['REMOTE_ADDR'] ?? '';
        return is_string($remote) ? $remote : '';
    }
}
