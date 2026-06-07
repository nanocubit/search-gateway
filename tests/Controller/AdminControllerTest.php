<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Controller;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use SearchGateway\Admin\AdminAuth;
use SearchGateway\Admin\AnalyticsAdminService;
use SearchGateway\Admin\HealthAdminService;
use SearchGateway\Admin\KeyAdminService;
use SearchGateway\Admin\RouteAdminService;
use SearchGateway\Analytics\SearchAnalytics;
use SearchGateway\ApiKey\ApiKeyManager;
use SearchGateway\ApiKey\InMemoryApiKeyStore;
use SearchGateway\Controller\AdminController;
use SearchGateway\Router\InMemoryRouteRegistry;

final class AdminControllerTest extends TestCase
{
    private const ADMIN_TOKEN = 'test-admin-token';

    private Psr17Factory $factory;
    private AdminController $controller;
    private InMemoryRouteRegistry $registry;
    private ApiKeyManager $keys;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->registry = new InMemoryRouteRegistry();
        $this->keys = new ApiKeyManager(new InMemoryApiKeyStore());

        $auth = new AdminAuth(token: self::ADMIN_TOKEN);
        $this->controller = new AdminController(
            $auth,
            new RouteAdminService($this->registry),
            new KeyAdminService($this->keys),
            new HealthAdminService(analytics: new SearchAnalytics()),
            new AnalyticsAdminService(new SearchAnalytics()),
        );
    }

    public function testReturns503WhenAdminDisabled(): void
    {
        $auth = new AdminAuth(token: null, envVar: null);
        $controller = new AdminController(
            $auth,
            new RouteAdminService($this->registry),
            new KeyAdminService($this->keys),
            new HealthAdminService(),
            new AnalyticsAdminService(),
        );
        $request = $this->factory->createServerRequest('GET', '/admin/health');

        $response = $controller->handle($request);
        self::assertSame(503, $response->getStatusCode());
    }

    public function testReturns401WhenHeaderMissing(): void
    {
        $request = $this->factory->createServerRequest('GET', '/admin/routes');
        $response = $this->controller->handle($request);
        self::assertSame(401, $response->getStatusCode());
    }

    public function testReturns401OnBadToken(): void
    {
        $request = $this->factory->createServerRequest('GET', '/admin/routes')
            ->withHeader('Authorization', 'Bearer wrong');
        $response = $this->controller->handle($request);
        self::assertSame(401, $response->getStatusCode());
    }

    public function testListRoutesReturnsRegisteredRoutes(): void
    {
        $this->registry->register(new \SearchGateway\Router\Route(
            name: 'v1.web',
            method: 'POST',
            path: '/v1/search/web',
            action: 'searchWeb',
        ));

        $response = $this->controller->handle($this->adminRequest('GET', '/admin/routes'));
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($body['ok']);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertSame(1, $data['count']);
    }

    public function testRegisterRouteViaPost(): void
    {
        $body = json_encode([
            'name' => 'v1.gen',
            'method' => 'post',
            'path' => '/v1/search/gen',
            'action' => 'searchGen',
            'scopes' => ['search:gen'],
        ], JSON_THROW_ON_ERROR);

        $request = $this->factory->createServerRequest('POST', '/admin/routes')
            ->withHeader('Authorization', 'Bearer ' . self::ADMIN_TOKEN)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream($body));

        $response = $this->controller->handle($request);
        $data = $this->json($response);

        self::assertSame(201, $response->getStatusCode());
        $created = $data['data'];
        self::assertIsArray($created);
        self::assertSame('v1.gen', $created['name']);
        self::assertNotNull($this->registry->get('v1.gen'));
    }

    public function testGetRouteByNameReturns404WhenMissing(): void
    {
        $response = $this->controller->handle($this->adminRequest('GET', '/admin/routes/missing'));
        self::assertSame(404, $response->getStatusCode());
    }

    public function testDeleteRouteRemovesIt(): void
    {
        $this->registry->register(new \SearchGateway\Router\Route(
            name: 'v1.web',
            method: 'POST',
            path: '/v1/search/web',
            action: 'searchWeb',
        ));

        $response = $this->controller->handle($this->adminRequest('DELETE', '/admin/routes/v1.web'));
        $data = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        $removed = $data['data'];
        self::assertIsArray($removed);
        self::assertSame('v1.web', $removed['removed']);
        self::assertNull($this->registry->get('v1.web'));
    }

    public function testCreateKeyReturnsRawKey(): void
    {
        $body = json_encode(['owner' => 'user-1', 'scopes' => ['search:web']], JSON_THROW_ON_ERROR);
        $request = $this->factory->createServerRequest('POST', '/admin/keys')
            ->withHeader('Authorization', 'Bearer ' . self::ADMIN_TOKEN)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream($body));

        $response = $this->controller->handle($request);
        $data = $this->json($response);

        self::assertSame(201, $response->getStatusCode());
        $created = $data['data'];
        self::assertIsArray($created);
        self::assertArrayHasKey('rawKey', $created);
        self::assertSame('user-1', $created['owner']);
    }

    public function testRevokeKeyReturns404ForUnknownKey(): void
    {
        $response = $this->controller->handle($this->adminRequest('DELETE', '/admin/keys/unknown'));
        self::assertSame(404, $response->getStatusCode());
    }

    public function testHealthEndpointReturnsReport(): void
    {
        $response = $this->controller->handle($this->adminRequest('GET', '/admin/health'));
        $data = $this->json($response);
        $report = $data['data'];
        self::assertIsArray($report);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('status', $report);
        self::assertArrayHasKey('providers', $report);
    }

    public function testAnalyticsEndpointReturnsSummary(): void
    {
        $response = $this->controller->handle($this->adminRequest('GET', '/admin/analytics'));
        $data = $this->json($response);
        $summary = $data['data'];
        self::assertIsArray($summary);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($summary['enabled']);
    }

    public function testUnknownEndpointReturns404(): void
    {
        $response = $this->controller->handle($this->adminRequest('GET', '/admin/unknown'));
        self::assertSame(404, $response->getStatusCode());
    }

    public function testMethodNotAllowedReturns405(): void
    {
        $response = $this->controller->handle($this->adminRequest('PUT', '/admin/routes'));
        self::assertSame(405, $response->getStatusCode());
    }

    public function testBadJsonBodyReturns400(): void
    {
        $request = $this->factory->createServerRequest('POST', '/admin/routes')
            ->withHeader('Authorization', 'Bearer ' . self::ADMIN_TOKEN)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream('{not json'));

        $response = $this->controller->handle($request);
        self::assertSame(400, $response->getStatusCode());
    }

    private function adminRequest(string $method, string $path): \Psr\Http\Message\ServerRequestInterface
    {
        return $this->factory->createServerRequest($method, $path)
            ->withHeader('Authorization', 'Bearer ' . self::ADMIN_TOKEN);
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
