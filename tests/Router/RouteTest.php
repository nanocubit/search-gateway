<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Router;

use PHPUnit\Framework\TestCase;
use SearchGateway\Router\Route;

final class RouteTest extends TestCase
{
    public function testStoresAllConstructorArguments(): void
    {
        $route = new Route(
            name: 'v1.search.web',
            method: Route::METHOD_POST,
            path: '/v1/search/web',
            action: Route::ACTION_SEARCH_WEB,
            requiredScopes: ['search:web'],
            rateLimit: ['limit' => 60, 'window' => 60, 'key' => 'web'],
            config: ['timeout' => 5.0],
        );

        self::assertSame('v1.search.web', $route->name);
        self::assertSame(Route::METHOD_POST, $route->method);
        self::assertSame('/v1/search/web', $route->path);
        self::assertSame(Route::ACTION_SEARCH_WEB, $route->action);
        self::assertSame(['search:web'], $route->requiredScopes);
        self::assertSame(['limit' => 60, 'window' => 60, 'key' => 'web'], $route->rateLimit);
        self::assertSame(['timeout' => 5.0], $route->config);
        self::assertSame([], $route->decorators);
        self::assertSame([], $route->pathParams);
    }

    public function testWithPathParamsReturnsCopyWithFilledParams(): void
    {
        $route = new Route(
            name: 'v1.search.byVersion',
            method: Route::METHOD_GET,
            path: '/v:version/search/web',
            action: Route::ACTION_SEARCH_WEB,
        );

        $filled = $route->withPathParams(['version' => '1']);

        self::assertNotSame($route, $filled);
        self::assertSame([], $route->pathParams);
        self::assertSame(['version' => '1'], $filled->pathParams);
        self::assertSame('v1.search.byVersion', $filled->name);
    }

    public function testMethodMatchesIsCaseInsensitive(): void
    {
        $route = new Route(
            name: 'r',
            method: Route::METHOD_POST,
            path: '/x',
            action: Route::ACTION_SEARCH_WEB,
        );

        self::assertTrue($route->methodMatches('post'));
        self::assertTrue($route->methodMatches('POST'));
        self::assertFalse($route->methodMatches('GET'));
    }

    public function testMethodAnyMatchesAllHttpVerbs(): void
    {
        $route = new Route(
            name: 'r',
            method: Route::METHOD_ANY,
            path: '/x',
            action: Route::ACTION_SEARCH_WEB,
        );

        self::assertTrue($route->methodMatches('GET'));
        self::assertTrue($route->methodMatches('POST'));
        self::assertTrue($route->methodMatches('DELETE'));
        self::assertTrue($route->methodMatches('PATCH'));
        self::assertTrue($route->methodMatches('OPTIONS'));
    }

    public function testResolveBuilderReturnsInstanceWhenBuilderIsObject(): void
    {
        $builder = new \SearchGateway\Builder\GatewayBuilder();
        $route = new Route(
            name: 'r',
            method: Route::METHOD_GET,
            path: '/x',
            action: Route::ACTION_SEARCH_WEB,
            builder: $builder,
        );

        self::assertSame($builder, $route->resolveBuilder());
    }

    public function testResolveBuilderInvokesCallableWhenBuilderIsClosure(): void
    {
        $builder = new \SearchGateway\Builder\GatewayBuilder();
        $called = false;
        $route = new Route(
            name: 'r',
            method: Route::METHOD_GET,
            path: '/x',
            action: Route::ACTION_SEARCH_WEB,
            builder: function () use ($builder, &$called) {
                $called = true;
                return $builder;
            },
        );

        $result = $route->resolveBuilder();
        self::assertTrue($called);
        self::assertSame($builder, $result);
    }

    public function testResolveBuilderReturnsNullWhenBuilderIsNull(): void
    {
        $route = new Route(
            name: 'r',
            method: Route::METHOD_GET,
            path: '/x',
            action: Route::ACTION_SEARCH_WEB,
        );

        self::assertNull($route->resolveBuilder());
    }

    public function testActionConstantsAreStable(): void
    {
        self::assertSame('searchWeb', Route::ACTION_SEARCH_WEB);
        self::assertSame('searchGen', Route::ACTION_SEARCH_GEN);
        self::assertSame('llmContext', Route::ACTION_LLM_CONTEXT);
        self::assertSame('hybrid', Route::ACTION_HYBRID);
    }
}
