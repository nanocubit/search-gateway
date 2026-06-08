<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Controller;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use SearchGateway\Builder\GatewayBuilder;
use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Controller\SearchGatewayController;
use SearchGateway\Router\InMemoryRouteRegistry;
use SearchGateway\Router\Route;
use SearchGateway\Router\RoutePresets;
use SearchGateway\Router\RouteResolver;

final class SearchGatewayControllerTest extends TestCase
{
    private Psr17Factory $factory;
    private InMemoryRouteRegistry $registry;
    private RouteResolver $resolver;
    private GatewayBuilder $builder;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->registry = new InMemoryRouteRegistry();
        $this->resolver = new RouteResolver();
        $this->builder = (new GatewayBuilder())->addProvider(new FakeSearchGateway());
    }

    public function testReturns404ForUnknownPath(): void
    {
        $controller = $this->makeController();
        $request = $this->factory->createServerRequest('POST', '/missing');

        $response = $controller->handle($request);
        self::assertSame(404, $response->getStatusCode());
        $body = $this->json($response);
        self::assertFalse($body['ok']);
    }

    public function testDispatchesToSearchWeb(): void
    {
        $this->registry->register(new Route(
            name: 'v1.web',
            method: Route::METHOD_POST,
            path: '/v1/search/web',
            action: Route::ACTION_SEARCH_WEB,
        ));
        $controller = $this->makeController();
        $request = $this->jsonRequest('POST', '/v1/search/web', ['query' => 'php']);

        $response = $controller->handle($request);
        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('searchWeb', $body['action']);
        self::assertTrue($body['ok']);
        self::assertNotEmpty($body['payload']);
    }

    public function testDispatchesToSearchGen(): void
    {
        $this->registry->register(new Route(
            name: 'v1.gen',
            method: Route::METHOD_POST,
            path: '/v1/search/gen',
            action: Route::ACTION_SEARCH_GEN,
        ));
        $controller = $this->makeController();
        $request = $this->jsonRequest('POST', '/v1/search/gen', ['query' => 'php']);

        $response = $controller->handle($request);
        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('searchGen', $body['action']);
        $payload = $body['payload'];
        self::assertIsArray($payload);
        self::assertSame('mocked', $payload['answer']);
    }

    public function testDispatchesToLlContext(): void
    {
        $this->registry->register(new Route(
            name: 'v1.llm',
            method: Route::METHOD_POST,
            path: '/v1/llm/context',
            action: Route::ACTION_LLM_CONTEXT,
        ));
        $controller = $this->makeController();
        $request = $this->jsonRequest('POST', '/v1/llm/context', ['query' => 'php']);

        $response = $controller->handle($request);
        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('llmContext', $body['action']);
    }

    public function testCapturesPathParamsInPayload(): void
    {
        $this->registry->register(new Route(
            name: 'v1.byVersion',
            method: Route::METHOD_POST,
            path: '/:version/search/web',
            action: Route::ACTION_SEARCH_WEB,
        ));
        $controller = $this->makeController();
        $request = $this->jsonRequest('POST', '/v2/search/web', ['query' => 'php']);

        $response = $controller->handle($request);
        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('v1.byVersion', $body['route']);
    }

    public function testReturns400OnMissingQuery(): void
    {
        $this->registry->register(new Route(
            name: 'v1.web',
            method: Route::METHOD_POST,
            path: '/v1/search/web',
            action: Route::ACTION_SEARCH_WEB,
        ));
        $controller = $this->makeController();
        $request = $this->jsonRequest('POST', '/v1/search/web', []);

        $response = $controller->handle($request);
        self::assertSame(400, $response->getStatusCode());
    }

    public function testCapturesLatencyInMeta(): void
    {
        $this->registry->register(new Route(
            name: 'v1.web',
            method: Route::METHOD_POST,
            path: '/v1/search/web',
            action: Route::ACTION_SEARCH_WEB,
        ));
        $controller = $this->makeController();
        $request = $this->jsonRequest('POST', '/v1/search/web', ['query' => 'php']);

        $response = $controller->handle($request);
        $body = $this->json($response);
        $meta = $body['meta'];
        self::assertIsArray($meta);
        self::assertArrayHasKey('latency_ms', $meta);
    }

    public function testDispatchesHybridMergingContextAndWeb(): void
    {
        $this->registry->register(new Route(
            name: 'v1.hybrid',
            method: Route::METHOD_POST,
            path: '/v1/search/hybrid',
            action: Route::ACTION_HYBRID,
        ));
        $controller = $this->makeController();
        $request = $this->jsonRequest('POST', '/v1/search/hybrid', ['query' => 'php']);

        $response = $controller->handle($request);
        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        $payload = $body['payload'];
        self::assertIsArray($payload);
        $urls = array_column($payload, 'url');
        self::assertContains('https://shared.example.com/x', $urls);
        self::assertContains('https://web-only.example.com/y', $urls);
    }

    public function testCustomBuilderOnRouteOverridesDefault(): void
    {
        $customBuilder = (new GatewayBuilder())->addProvider(new AltSearchGateway());
        $this->registry->register(new Route(
            name: 'v1.alt',
            method: Route::METHOD_POST,
            path: '/v1/alt',
            action: Route::ACTION_SEARCH_GEN,
            builder: $customBuilder,
        ));
        $controller = $this->makeController();
        $request = $this->jsonRequest('POST', '/v1/alt', ['query' => 'php']);

        $response = $controller->handle($request);
        $body = $this->json($response);
        $payload = $body['payload'];
        self::assertIsArray($payload);
        self::assertSame('alt', $payload['answer']);
    }

    public function testDispatchesBrowserHistoryRouteViaPreset(): void
    {
        foreach (RoutePresets::browserHistory() as $route) {
            $this->registry->register($route);
        }
        $controller = $this->makeController();
        $request = $this->factory
            ->createServerRequest('GET', '/v1/browser/history?q=test');

        $response = $controller->handle($request);
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($body['ok']);
        self::assertSame('searchWeb', $body['action']);
        self::assertIsArray($body['payload']);
        self::assertCount(2, $body['payload']);
    }

    public function testReturns500WhenBuilderFails(): void
    {
        $this->registry->register(new Route(
            name: 'v1.web',
            method: Route::METHOD_POST,
            path: '/v1/search/web',
            action: Route::ACTION_SEARCH_WEB,
        ));
        $failingBuilder = new class extends GatewayBuilder {
            public function build(): SearchGatewayInterface
            {
                throw new \RuntimeException('boom');
            }
        };
        $controller = new SearchGatewayController(
            $this->registry,
            $this->resolver,
            defaultBuilder: $failingBuilder,
        );
        $request = $this->jsonRequest('POST', '/v1/search/web', ['query' => 'php']);

        $response = $controller->handle($request);
        self::assertSame(500, $response->getStatusCode());
    }

    private function makeController(): SearchGatewayController
    {
        return new SearchGatewayController(
            $this->registry,
            $this->resolver,
            defaultBuilder: $this->builder,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(string $method, string $path, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        $request = $this->factory->createServerRequest($method, $path);
        return $request->withBody($this->factory->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}

final class FakeSearchGateway implements SearchGatewayInterface
{
    public function providerName(): string
    {
        return 'fake';
    }

    public function searchWeb(string $query, array $options = []): array
    {
        return [
            ['url' => 'https://shared.example.com/x', 'title' => 'X', 'domain' => 'shared.example.com', 'passage' => 'p', 'score' => 0.9],
            ['url' => 'https://web-only.example.com/y', 'title' => 'Y', 'domain' => 'web-only.example.com', 'passage' => 'p', 'score' => 0.7],
        ];
    }

    public function searchNews(string $query, array $options = []): array
    {
        return [];
    }

    public function searchImages(string $query, array $options = []): array
    {
        return [];
    }

    public function searchGen(string $query, array $options = []): GenerativeSearchResultDTO
    {
        return new GenerativeSearchResultDTO(answer: 'mocked', sources: [], meta: ['provider' => 'fake']);
    }

    public function wordstat(string $query, array $options = []): array
    {
        return [];
    }

    public function llmContext(string $query, array $options = []): array
    {
        return [
            ['url' => 'https://shared.example.com/x', 'title' => 'X', 'domain' => 'shared.example.com', 'passage' => 'p', 'score' => 0.95],
            ['url' => 'https://ctx-only.example.com/y', 'title' => 'Y', 'domain' => 'ctx-only.example.com', 'passage' => 'p', 'score' => 0.8],
        ];
    }
}

final class AltSearchGateway implements SearchGatewayInterface
{
    public function providerName(): string
    {
        return 'alt';
    }

    public function searchWeb(string $query, array $options = []): array
    {
        return [['url' => 'https://web-only.example.com/y', 'title' => 'Y', 'domain' => 'web-only', 'passage' => 'p', 'score' => 0.7]];
    }

    public function searchNews(string $query, array $options = []): array
    {
        return [];
    }

    public function searchImages(string $query, array $options = []): array
    {
        return [];
    }

    public function searchGen(string $query, array $options = []): GenerativeSearchResultDTO
    {
        return new GenerativeSearchResultDTO(answer: 'alt', sources: [], meta: ['provider' => 'alt']);
    }

    public function wordstat(string $query, array $options = []): array
    {
        return [];
    }

    public function llmContext(string $query, array $options = []): array
    {
        return [];
    }
}
