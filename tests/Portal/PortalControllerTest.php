<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Portal;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use PHPUnit\Framework\TestCase;
use SearchGateway\Portal\OpenApiGenerator;
use SearchGateway\Portal\PortalController;
use SearchGateway\Router\InMemoryRouteRegistry;
use SearchGateway\Router\Route;

final class PortalControllerTest extends TestCase
{
    private PortalController $controller;
    private Psr17Factory $factory;
    private ServerRequestCreator $creator;

    protected function setUp(): void
    {
        $registry = new InMemoryRouteRegistry();
        $registry->register(new Route(
            name: 'v1.web', method: Route::METHOD_POST, path: '/v1/search/web', action: Route::ACTION_SEARCH_WEB,
        ));
        $this->controller = new PortalController(new OpenApiGenerator($registry));
        $this->factory = new Psr17Factory();
        $this->creator = new ServerRequestCreator($this->factory, $this->factory, $this->factory, $this->factory);
    }

    public function testOpenApiJsonReturnsValidJson(): void
    {
        $request = $this->creator->fromArrays(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/docs/openapi.json']);
        $response = $this->controller->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('3.0.3', $body['openapi']);
        self::assertArrayHasKey('/v1/search/web', $body['paths']);
    }

    public function testSwaggerUiReturnsHtmlWithCdnReference(): void
    {
        $request = $this->creator->fromArrays(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/docs']);
        $response = $this->controller->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));

        $body = (string) $response->getBody();
        self::assertStringContainsString('<div id="swagger-ui">', $body);
        self::assertStringContainsString('cdn.jsdelivr.net/npm/swagger-ui-dist@', $body);
        self::assertStringContainsString('url: \'/docs/openapi.json\'', $body);
    }

    public function testSandboxReturnsHtmlWithForm(): void
    {
        $request = $this->creator->fromArrays(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/docs/sandbox']);
        $response = $this->controller->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();

        self::assertStringContainsString('Search Gateway — Sandbox', $body);
        self::assertStringContainsString('id="route"', $body);
        self::assertStringContainsString('id="apiKey"', $body);
        self::assertStringContainsString('fetch(\'/docs/openapi.json\')', $body);
    }

    public function testPortalHomeReturnsHtmlWithLinks(): void
    {
        $request = $this->creator->fromArrays(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/docs/portal']);
        $response = $this->controller->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();

        self::assertStringContainsString('Search Gateway — Developer Portal', $body);
        self::assertStringContainsString('href="/docs"', $body);
        self::assertStringContainsString('href="/docs/sandbox"', $body);
    }

    public function testUnknownEndpointReturns404WithJsonError(): void
    {
        $request = $this->creator->fromArrays(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/docs/unknown-thing']);
        $response = $this->controller->handle($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse((bool) ($body['ok'] ?? true));
        self::assertStringContainsString('Unknown portal endpoint', (string) ($body['error'] ?? ''));
    }

    public function testPostToOpenApiJsonStillWorks(): void
    {
        $request = $this->creator->fromArrays(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/docs/openapi.json']);
        $response = $this->controller->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function testNoCdnMarkerLeaksIntoHtml(): void
    {
        $request = $this->creator->fromArrays(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/docs']);
        $response = $this->controller->handle($request);

        $body = (string) $response->getBody();
        self::assertStringNotContainsString('__SWAGGER_CDN__', $body);
    }

    public function testHtmlResponseUsesUtf8Charset(): void
    {
        $request = $this->creator->fromArrays(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/docs/portal']);
        $response = $this->controller->handle($request);

        self::assertStringContainsString('charset=utf-8', $response->getHeaderLine('Content-Type'));
    }
}
