<?php

declare(strict_types=1);

namespace SearchGateway\Controller;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SearchGateway\Builder\GatewayBuilder;
use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Contract\SearchGatewayException;
use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Formatter\ResponseFormatter;
use SearchGateway\Guardrails\SearchGuardrails;
use SearchGateway\Http\JsonResponse;
use SearchGateway\Plugin\PluginContext;
use SearchGateway\Plugin\PluginPipeline;
use SearchGateway\Request\SearchRequest;
use SearchGateway\Request\SearchResponse;
use SearchGateway\Router\Route;
use SearchGateway\Router\RouteRegistryInterface;
use SearchGateway\Router\RouteResolver;

final class SearchGatewayController implements RequestHandlerInterface
{
    private Psr17Factory $factory;

    public function __construct(
        private readonly RouteRegistryInterface $registry,
        private readonly RouteResolver $resolver,
        private readonly ?PluginPipeline $pipeline = null,
        private readonly ?SearchGuardrails $guardrails = null,
        private readonly ?ResponseFormatter $formatter = null,
        private readonly ?GatewayBuilder $defaultBuilder = null,
    ) {
        $this->factory = new Psr17Factory();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $method = $request->getMethod();
        $path = '/' . ltrim($request->getUri()->getPath(), '/');

        $matches = $this->registry->match($method, $path);
        if ($matches === []) {
            return JsonResponse::create($this->response(), 404, [
                'ok' => false,
                'error' => sprintf('No route matches %s %s', $method, $path),
            ]);
        }

        /** @var Route $route */
        $route = $matches[0][0];

        try {
            $searchRequest = $this->resolver->resolve($route, $request);
        } catch (SearchGatewayException $e) {
            return JsonResponse::create($this->response(), 400, [
                'ok' => false,
                'error' => $e->getMessage(),
            ]);
        }

        $pluginContext = $this->buildPluginContext();
        if ($this->pipeline !== null) {
            $searchRequest = $this->pipeline->runBefore($searchRequest, $pluginContext);
        }

        $start = microtime(true);
        $searchResponse = $this->dispatch($route, $searchRequest);
        $latency = (microtime(true) - $start) * 1000.0;
        $searchResponse = $searchResponse->withMetaValue('latency_ms', $latency);

        if ($this->guardrails !== null && $searchResponse->isOk() && $searchResponse->payload instanceof GenerativeSearchResultDTO) {
            $violations = $this->guardrails->validate($searchResponse->payload);
            $searchResponse = $searchResponse->withMetaValue('guardrail_violations', $violations);
            if ($violations !== []) {
                $searchResponse = $searchResponse->withStatus(422);
            }
        }

        if ($this->pipeline !== null) {
            $searchResponse = $this->pipeline->runAfter($searchResponse, $pluginContext);
        }

        if ($this->formatter !== null && $searchResponse->payload instanceof GenerativeSearchResultDTO) {
            $formatted = $this->formatter->toMarkdown($searchResponse->payload);
            $searchResponse = $searchResponse->withMetaValue('formatted_markdown', $formatted);
        }

        $body = $searchResponse->toArray();
        return JsonResponse::create($this->response(), $searchResponse->status, $body);
    }

    private function dispatch(Route $route, SearchRequest $request): SearchResponse
    {
        try {
            $gateway = $this->resolveGateway($route);
            return $this->invokeAction($gateway, $route, $request);
        } catch (\Throwable $e) {
            return SearchResponse::error($route->action, $route->name, $e->getMessage(), 500);
        }
    }

    private function resolveGateway(Route $route): SearchGatewayInterface
    {
        $builder = $route->resolveBuilder();
        if ($builder === null) {
            $builder = $this->defaultBuilder;
        }
        if ($builder === null) {
            throw new SearchGatewayException('No gateway builder available for route ' . $route->name);
        }
        $gateway = $builder->build();
        if (!$gateway instanceof SearchGatewayInterface) {
            throw new SearchGatewayException('Builder did not return a SearchGatewayInterface');
        }
        return $gateway;
    }

    private function invokeAction(SearchGatewayInterface $gateway, Route $route, SearchRequest $request): SearchResponse
    {
        $options = $request->filters;
        if ($request->providers !== []) {
            $options['providers'] = $request->providers;
        }
        if ($request->llm !== []) {
            $options['llm'] = $request->llm;
        }
        $options['routeName'] = $route->name;

        $action = $route->action;
        $result = match ($action) {
            Route::ACTION_SEARCH_WEB => $gateway->searchWeb($request->query, $options),
            Route::ACTION_SEARCH_NEWS => $gateway->searchNews($request->query, $options),
            Route::ACTION_SEARCH_IMAGES => $gateway->searchImages($request->query, $options),
            Route::ACTION_SEARCH_GEN => $gateway->searchGen($request->query, $options),
            Route::ACTION_LLM_CONTEXT => $gateway->llmContext($request->query, $options),
            Route::ACTION_WORDSTAT => $gateway->wordstat($request->query, $options),
            Route::ACTION_HYBRID => $this->dispatchHybrid($gateway, $request, $options),
            default => throw new SearchGatewayException('Unknown action: ' . $action),
        };

        if ($result instanceof GenerativeSearchResultDTO) {
            return SearchResponse::ok($action, $route->name, $result);
        }
        return SearchResponse::ok($action, $route->name, $result);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    private function dispatchHybrid(SearchGatewayInterface $gateway, SearchRequest $request, array $options): array
    {
        $context = $gateway->llmContext($request->query, $options);
        $web = $gateway->searchWeb($request->query, $options);
        $seen = [];
        $merged = [];
        foreach ($context as $doc) {
            if (!is_array($doc)) {
                $merged[] = $doc;
                continue;
            }
            $url = $this->extractUrl($doc);
            if ($url !== '') {
                $seen[$url] = true;
            }
            $merged[] = $doc;
        }
        foreach ($web as $doc) {
            if (!is_array($doc)) {
                continue;
            }
            $url = $this->extractUrl($doc);
            if ($url !== '' && isset($seen[$url])) {
                continue;
            }
            $merged[] = $doc;
        }
        return $merged;
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function extractUrl(array $doc): string
    {
        $raw = $doc['url'] ?? '';
        return is_scalar($raw) ? (string) $raw : '';
    }

    private function buildPluginContext(): PluginContext
    {
        $ctx = PluginContext::empty();
        if ($this->formatter !== null) {
            $ctx = $ctx->withFormatter($this->formatter);
        }
        return $ctx;
    }

    private function response(): ResponseInterface
    {
        return $this->factory->createResponse();
    }
}
