<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SearchGateway\Admin\RouteAdminService;
use SearchGateway\Contract\SearchGatewayException;
use SearchGateway\Router\InMemoryRouteRegistry;
use SearchGateway\Router\Route;

final class RouteAdminServiceTest extends TestCase
{
    private RouteAdminService $service;
    private InMemoryRouteRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new InMemoryRouteRegistry();
        $this->service = new RouteAdminService($this->registry);
    }

    public function testListReturnsAllRegisteredRoutes(): void
    {
        $this->registry->register(new Route(name: 'a', method: 'GET', path: '/a', action: Route::ACTION_SEARCH_WEB));
        $this->registry->register(new Route(name: 'b', method: 'POST', path: '/b', action: Route::ACTION_SEARCH_GEN));

        $rows = $this->service->list();

        self::assertCount(2, $rows);
        self::assertSame('a', $rows[0]['name']);
        self::assertSame('b', $rows[1]['name']);
    }

    public function testGetReturnsRouteRowOrNull(): void
    {
        $this->registry->register(new Route(name: 'a', method: 'GET', path: '/a', action: Route::ACTION_SEARCH_WEB));

        self::assertNotNull($this->service->get('a'));
        self::assertNull($this->service->get('missing'));
    }

    public function testRegisterAddsRouteWithProvidedFields(): void
    {
        $row = $this->service->register([
            'name' => 'v1.web',
            'method' => 'post',
            'path' => '/v1/search/web',
            'action' => 'searchWeb',
            'scopes' => ['search:web'],
            'rateLimit' => ['limit' => 100, 'window' => 60],
            'config' => ['timeout' => 5.0],
        ]);

        self::assertSame('v1.web', $row['name']);
        self::assertSame('POST', $row['method']);
        self::assertSame('/v1/search/web', $row['path']);
        self::assertSame('searchWeb', $row['action']);
        self::assertSame(['search:web'], $row['scopes']);
        self::assertSame(['limit' => 100, 'window' => 60], $row['rateLimit']);
        self::assertSame(['timeout' => 5.0], $row['config']);
    }

    public function testRegisterThrowsOnMissingRequiredField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Field "name" is required');
        $this->service->register(['method' => 'GET', 'path' => '/x', 'action' => 'searchWeb']);
    }

    public function testRegisterThrowsOnDuplicateName(): void
    {
        $this->registry->register(new Route(name: 'a', method: 'GET', path: '/a', action: Route::ACTION_SEARCH_WEB));

        $this->expectException(SearchGatewayException::class);
        $this->service->register(['name' => 'a', 'method' => 'GET', 'path' => '/b', 'action' => 'searchWeb']);
    }

    public function testRemoveReturnsTrueOnSuccessAndFalseOnMissing(): void
    {
        $this->registry->register(new Route(name: 'a', method: 'GET', path: '/a', action: Route::ACTION_SEARCH_WEB));

        self::assertTrue($this->service->remove('a'));
        self::assertFalse($this->service->remove('a'));
    }

    public function testCountDelegatesToRegistry(): void
    {
        self::assertSame(0, $this->service->count());
        $this->service->register(['name' => 'a', 'method' => 'GET', 'path' => '/a', 'action' => 'searchWeb']);
        self::assertSame(1, $this->service->count());
    }
}
