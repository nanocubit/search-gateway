<?php

declare(strict_types=1);

namespace SearchGateway\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use SearchGateway\Analytics\SearchAnalytics;
use SearchGateway\Contract\SearchGatewayException;
use SearchGateway\Http\JsonResponse;
use SearchGateway\Plugin\PluginContext;
use SearchGateway\Plugin\PluginPipeline;
use SearchGateway\Router\Route;
use SearchGateway\Router\RouteRegistryInterface;
use SearchGateway\Router\RouteResolver;
use SearchGateway\Streaming\SseEmitter;

final class StreamController implements RequestHandlerInterface
{
    private Psr17Factory $factory;

    public function __construct(
        private readonly RouteRegistryInterface $registry,
        private readonly RouteResolver $resolver,
        private readonly PluginPipeline $pipeline,
        private readonly SearchAnalytics $analytics,
        private readonly SseEmitter $emitter = new SseEmitter(),
    ) {
        $this->factory = new Psr17Factory();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = '/' . ltrim($request->getUri()->getPath(), '/');
        $method = strtoupper($request->getMethod());

        $matches = $this->registry->match($method, $path);
        foreach ($matches as [$route, $pathParams]) {
            return $this->dispatch($request, $route, $pathParams);
        }

        return JsonResponse::create(
            $this->factory->createResponse(404),
            404,
            ['ok' => false, 'error' => 'No streaming route registered for: ' . $method . ' ' . $path],
        );
    }

    /**
     * @param array<string, string> $pathParams
     */
    private function dispatch(ServerRequestInterface $request, Route $route, array $pathParams): ResponseInterface
    {
        $body = $this->readJsonBody($request);
        try {
            $searchRequest = $this->resolver->resolve($route, $request, $body);
        } catch (SearchGatewayException $e) {
            return JsonResponse::create(
                $this->factory->createResponse($e->getCode() ?: 400),
                $e->getCode() ?: 400,
                ['ok' => false, 'error' => $e->getMessage()],
            );
        }

        $searchRequest = $searchRequest->withPathParams($pathParams);

        $ctx = PluginContext::empty()->withAnalytics($this->analytics);
        $searchRequest = $this->pipeline->runBefore($searchRequest, $ctx);

        $generator = $this->resolveStreamSource($route, $searchRequest);
        if ($generator === null) {
            return JsonResponse::create(
                $this->factory->createResponse(500),
                500,
                ['ok' => false, 'error' => 'Route has no stream source configured'],
            );
        }

        $chunks = $this->safeStream($generator, $searchRequest);
        $response = $this->emitter->emit($chunks);
        $this->analytics->record([
            'query' => $searchRequest->query,
            'provider' => $searchRequest->providers[0] ?? 'unknown',
            'latencyMs' => 0.0,
            'success' => true,
            'status' => 200,
            'route' => $route->name,
        ]);
        $stub = \SearchGateway\Request\SearchResponse::ok('stream', $route->name, ['ok' => true]);
        $this->pipeline->runAfter($stub, $ctx);
        return $response;
    }

    private function resolveStreamSource(Route $route, mixed $searchRequest): ?callable
    {
        $config = $route->config;
        if (isset($config['stream_generator']) && is_callable($config['stream_generator'])) {
            return static fn (): iterable => ($config['stream_generator'])($searchRequest);
        }
        if (isset($config['stream_iterable']) && is_iterable($config['stream_iterable'])) {
            return static fn (): iterable => $config['stream_iterable'];
        }
        return null;
    }

    /**
     * @return iterable<int, string|array<string, mixed>>
     */
    private function safeStream(callable $source, mixed $searchRequest): iterable
    {
        $result = $source();
        if (!is_iterable($result)) {
            return;
        }
        foreach ($result as $chunk) {
            if (is_string($chunk) || is_array($chunk)) {
                yield $chunk;
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJsonBody(ServerRequestInterface $request): ?array
    {
        $raw = (string) $request->getBody();
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
