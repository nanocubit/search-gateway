<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Http\Middleware;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use SearchGateway\Http\Middleware\CorsMiddleware;

final class CorsMiddlewareTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    public function testOptionsRequestReturns204WithCorsHeaders(): void
    {
        $mw = new CorsMiddleware(allowedOrigins: ['https://example.com']);
        $request = $this->factory->createServerRequest('OPTIONS', '/v1/search/web');

        $response = $mw->process($request, $this->failHandler());

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertStringContainsString('POST', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertStringContainsString('Authorization', $response->getHeaderLine('Access-Control-Allow-Headers'));
    }

    public function testGetRequestAppliesCorsHeadersToResponse(): void
    {
        $mw = new CorsMiddleware(allowedOrigins: ['*']);
        $request = $this->factory->createServerRequest('GET', '/v1/search/web');

        $response = $mw->process($request, $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testEmptyOriginsDefaultsToWildcard(): void
    {
        $mw = new CorsMiddleware(allowedOrigins: []);
        $request = $this->factory->createServerRequest('GET', '/v1/search/web');

        $response = $mw->process($request, $this->okHandler());
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testMaxAgeHeaderIsApplied(): void
    {
        $mw = new CorsMiddleware(maxAge: 3600);
        $request = $this->factory->createServerRequest('GET', '/v1/search/web');

        $response = $mw->process($request, $this->okHandler());
        self::assertSame('3600', $response->getHeaderLine('Access-Control-Max-Age'));
    }

    private function okHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return new Response();
            }
        };
    }

    private function failHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                throw new \RuntimeException('Handler should not be called for OPTIONS preflight');
            }
        };
    }
}
