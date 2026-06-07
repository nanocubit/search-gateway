<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Router;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use SearchGateway\Contract\SearchGatewayException;
use SearchGateway\Router\Route;
use SearchGateway\Router\RouteResolver;

final class RouteResolverTest extends TestCase
{
    private RouteResolver $resolver;
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->resolver = new RouteResolver();
        $this->factory = new Psr17Factory();
    }

    public function testResolvesQueryFromJsonBody(): void
    {
        $route = $this->makeRoute('/v1/search/web', Route::ACTION_SEARCH_WEB);
        $request = $this->jsonRequest('POST', '/v1/search/web', ['query' => 'php frameworks']);

        $dto = $this->resolver->resolve($route, $request);

        self::assertSame('php frameworks', $dto->query);
        self::assertSame('v1.web', $dto->routeName);
    }

    public function testFallsBackToQueryStringWhenBodyMissing(): void
    {
        $route = $this->makeRoute('/v1/search/web', Route::ACTION_SEARCH_WEB);
        $request = $this->factory->createServerRequest('GET', '/v1/search/web?q=hello+world');

        $dto = $this->resolver->resolve($route, $request);

        self::assertSame('hello world', $dto->query);
    }

    public function testMissingQueryThrows(): void
    {
        $route = $this->makeRoute('/v1/search/web', Route::ACTION_SEARCH_WEB);
        $request = $this->jsonRequest('POST', '/v1/search/web', []);

        $this->expectException(SearchGatewayException::class);
        $this->expectExceptionMessage('Missing required field: query');
        $this->resolver->resolve($route, $request);
    }

    public function testProvidersAreFilteredToStringsOnly(): void
    {
        $route = $this->makeRoute('/v1/search/web', Route::ACTION_SEARCH_WEB);
        $request = $this->jsonRequest('POST', '/v1/search/web', [
            'query' => 'q',
            'providers' => ['yandex', 123, null, 'brave'],
        ]);

        $dto = $this->resolver->resolve($route, $request);

        self::assertSame(['yandex', 'brave'], $dto->providers);
    }

    public function testStreamFlagPreservesBool(): void
    {
        $route = $this->makeRoute('/v1/search/gen', Route::ACTION_SEARCH_GEN);
        $request = $this->jsonRequest('POST', '/v1/search/gen', [
            'query' => 'q',
            'stream' => true,
        ]);

        $dto = $this->resolver->resolve($route, $request);

        self::assertTrue($dto->stream);
    }

    public function testStreamFlagRejectsNonBoolAndDefaultsFalse(): void
    {
        $route = $this->makeRoute('/v1/search/gen', Route::ACTION_SEARCH_GEN);
        $request = $this->jsonRequest('POST', '/v1/search/gen', [
            'query' => 'q',
            'stream' => 'yes',
        ]);

        $dto = $this->resolver->resolve($route, $request);

        self::assertFalse($dto->stream);
    }

    public function testPathParamsAreCopiedFromRoute(): void
    {
        $route = (new Route(
            name: 'v1.byVersion',
            method: Route::METHOD_GET,
            path: '/v:version/search/web',
            action: Route::ACTION_SEARCH_WEB,
        ))->withPathParams(['version' => '2']);
        $request = $this->factory->createServerRequest('GET', '/v2/search/web?q=hi');

        $dto = $this->resolver->resolve($route, $request);

        self::assertSame('2', $dto->pathParams['version']);
    }

    public function testRequestContextIsMergedWithUserContext(): void
    {
        $route = $this->makeRoute('/v1/search/web', Route::ACTION_SEARCH_WEB);
        $request = $this->jsonRequest('POST', '/v1/search/web', [
            'query' => 'q',
            'userContext' => ['userId' => 'u-1'],
        ]);
        $request = $request->withHeader('X-Forwarded-For', '10.0.0.1')
            ->withHeader('User-Agent', 'curl/8.0');

        $dto = $this->resolver->resolve($route, $request);

        self::assertSame('u-1', $dto->userContext['userId']);
        self::assertSame('10.0.0.1', $dto->userContext['ip']);
        self::assertSame('curl/8.0', $dto->userContext['ua']);
        self::assertSame('POST', $dto->userContext['method']);
    }

    public function testUserContextIpDefaultsToRemoteAddr(): void
    {
        $route = $this->makeRoute('/v1/search/web', Route::ACTION_SEARCH_WEB);
        $request = $this->factory->createServerRequest('POST', '/v1/search/web', ['REMOTE_ADDR' => '192.168.0.1']);
        $request = $request->withBody($this->factory->createStream('{"query":"q"}'));

        $dto = $this->resolver->resolve($route, $request);

        self::assertSame('192.168.0.1', $dto->userContext['ip']);
    }

    public function testInvalidJsonBodyThrowsOnMissingQuery(): void
    {
        $route = $this->makeRoute('/v1/search/web', Route::ACTION_SEARCH_WEB);
        $request = $this->factory->createServerRequest('POST', '/v1/search/web')
            ->withBody($this->factory->createStream('{not json'));

        $this->expectException(SearchGatewayException::class);
        $this->expectExceptionMessage('Missing required field: query');
        $this->resolver->resolve($route, $request, null);
    }

    public function testGuardrailsAreFilteredToStrings(): void
    {
        $route = $this->makeRoute('/v1/search/web', Route::ACTION_SEARCH_WEB);
        $request = $this->jsonRequest('POST', '/v1/search/web', [
            'query' => 'q',
            'guardrails' => ['noPii', 99, 'noHallucinations'],
        ]);

        $dto = $this->resolver->resolve($route, $request);

        self::assertSame(['noPii', 'noHallucinations'], $dto->guardrails);
    }

    public function testApiKeyIdIsStringOrNull(): void
    {
        $route = $this->makeRoute('/v1/search/web', Route::ACTION_SEARCH_WEB);
        $request = $this->jsonRequest('POST', '/v1/search/web', [
            'query' => 'q',
            'apiKeyId' => 12345,
        ]);

        $dto = $this->resolver->resolve($route, $request);

        self::assertNull($dto->apiKeyId);
    }

    public function testLlmConfigIsPreserved(): void
    {
        $route = $this->makeRoute('/v1/search/gen', Route::ACTION_SEARCH_GEN);
        $request = $this->jsonRequest('POST', '/v1/search/gen', [
            'query' => 'q',
            'llm' => ['driver' => 'ollama', 'model' => 'llama3', 'temperature' => 0.4],
        ]);

        $dto = $this->resolver->resolve($route, $request);

        self::assertSame('ollama', $dto->llmDriver());
        self::assertSame('llama3', $dto->llmModel());
        self::assertSame(0.4, $dto->llm['temperature']);
    }

    public function testFiltersArePreservedAsArray(): void
    {
        $route = $this->makeRoute('/v1/search/web', Route::ACTION_SEARCH_WEB);
        $request = $this->jsonRequest('POST', '/v1/search/web', [
            'query' => 'q',
            'filters' => ['language' => 'ru', 'region' => 'ru-RU'],
        ]);

        $dto = $this->resolver->resolve($route, $request);

        self::assertSame(['language' => 'ru', 'region' => 'ru-RU'], $dto->filters);
    }

    private function makeRoute(string $path, string $action): Route
    {
        return new Route(
            name: $action === Route::ACTION_SEARCH_GEN ? 'v1.gen' : 'v1.web',
            method: Route::METHOD_POST,
            path: $path,
            action: $action,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(string $method, string $path, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        $request = $this->factory->createServerRequest($method, $path);
        $request = $request->withBody($this->factory->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        return $request;
    }
}
