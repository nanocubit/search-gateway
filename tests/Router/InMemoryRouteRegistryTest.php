<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Router;

use PHPUnit\Framework\TestCase;
use SearchGateway\Contract\SearchGatewayException;
use SearchGateway\Router\InMemoryRouteRegistry;
use SearchGateway\Router\Route;

final class InMemoryRouteRegistryTest extends TestCase
{
    public function testRegisterStoresRouteAndAllReturnsIt(): void
    {
        $registry = new InMemoryRouteRegistry();
        $route = $this->makeRoute('a', '/a', Route::METHOD_GET);
        $registry->register($route);

        self::assertSame(1, $registry->count());
        self::assertSame([$route], $registry->all());
    }

    public function testRegisterThrowsOnDuplicateName(): void
    {
        $registry = new InMemoryRouteRegistry();
        $registry->register($this->makeRoute('a', '/a', Route::METHOD_GET));

        $this->expectException(SearchGatewayException::class);
        $this->expectExceptionMessage('Route with name "a" is already registered');
        $registry->register($this->makeRoute('a', '/b', Route::METHOD_POST));
    }

    public function testGetReturnsRouteOrNull(): void
    {
        $registry = new InMemoryRouteRegistry();
        $route = $this->makeRoute('a', '/a', Route::METHOD_GET);
        $registry->register($route);

        self::assertSame($route, $registry->get('a'));
        self::assertNull($registry->get('missing'));
    }

    public function testMatchFindsByMethodAndStaticPath(): void
    {
        $registry = new InMemoryRouteRegistry();
        $registry->register($this->makeRoute('web', '/v1/search/web', Route::METHOD_POST));

        $matches = $registry->match('POST', '/v1/search/web');

        self::assertCount(1, $matches);
        self::assertSame('web', $matches[0][0]->name);
        self::assertSame([], $matches[0][1]);
    }

    public function testMatchIgnoresWrongMethod(): void
    {
        $registry = new InMemoryRouteRegistry();
        $registry->register($this->makeRoute('web', '/v1/search/web', Route::METHOD_POST));

        self::assertSame([], $registry->match('GET', '/v1/search/web'));
    }

    public function testMatchCapturesPathParams(): void
    {
        $registry = new InMemoryRouteRegistry();
        $registry->register($this->makeRoute('v', '/:version/search/web', Route::METHOD_GET));

        $matches = $registry->match('GET', '/2/search/web');

        self::assertCount(1, $matches);
        self::assertSame(['version' => '2'], $matches[0][1]);
        self::assertSame(['version' => '2'], $matches[0][0]->pathParams);
    }

    public function testMatchReturnsAllRoutesThatMatchPathAndMethod(): void
    {
        $registry = new InMemoryRouteRegistry();
        $registry->register($this->makeRoute('a', '/v1/search/:type', Route::METHOD_POST));
        $registry->register($this->makeRoute('b', '/v1/search/web', Route::METHOD_POST));

        $matches = $registry->match('POST', '/v1/search/web');

        self::assertCount(2, $matches);
        $names = array_map(static fn (array $m): string => $m[0]->name, $matches);
        sort($names);
        self::assertSame(['a', 'b'], $names);
    }

    public function testMatchReturnsEmptyWhenNoMatch(): void
    {
        $registry = new InMemoryRouteRegistry();
        $registry->register($this->makeRoute('a', '/v1/search/web', Route::METHOD_POST));

        self::assertSame([], $registry->match('POST', '/v2/search/web'));
    }

    public function testRemoveReturnsTrueOnSuccessAndFalseWhenMissing(): void
    {
        $registry = new InMemoryRouteRegistry();
        $registry->register($this->makeRoute('a', '/a', Route::METHOD_GET));

        self::assertTrue($registry->remove('a'));
        self::assertFalse($registry->remove('a'));
        self::assertSame(0, $registry->count());
    }

    public function testClearRemovesAllRoutesAndReturnsCount(): void
    {
        $registry = new InMemoryRouteRegistry();
        $registry->register($this->makeRoute('a', '/a', Route::METHOD_GET));
        $registry->register($this->makeRoute('b', '/b', Route::METHOD_GET));
        $registry->register($this->makeRoute('c', '/c', Route::METHOD_GET));

        self::assertSame(3, $registry->clear());
        self::assertSame(0, $registry->count());
        self::assertSame([], $registry->all());
    }

    public function testPreservesInsertionOrderInAll(): void
    {
        $registry = new InMemoryRouteRegistry();
        $a = $this->makeRoute('a', '/a', Route::METHOD_GET);
        $b = $this->makeRoute('b', '/b', Route::METHOD_GET);
        $c = $this->makeRoute('c', '/c', Route::METHOD_GET);
        $registry->register($a);
        $registry->register($b);
        $registry->register($c);

        $names = array_map(static fn (Route $r): string => $r->name, $registry->all());
        self::assertSame(['a', 'b', 'c'], $names);
    }

    public function testCountReturnsZeroOnEmptyRegistry(): void
    {
        self::assertSame(0, (new InMemoryRouteRegistry())->count());
    }

    private function makeRoute(string $name, string $path, string $method): Route
    {
        return new Route(
            name: $name,
            method: $method,
            path: $path,
            action: Route::ACTION_SEARCH_WEB,
        );
    }
}
