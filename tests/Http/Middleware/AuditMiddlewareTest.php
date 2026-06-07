<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Http\Middleware;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use SearchGateway\Analytics\SearchAnalytics;
use SearchGateway\Http\Middleware\AuditMiddleware;
use SearchGateway\Http\Middleware\AuthMiddleware;

final class AuditMiddlewareTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    public function testAddsResponseTimeHeader(): void
    {
        $mw = new AuditMiddleware();
        $request = $this->factory->createServerRequest('GET', '/v1/search/web');

        $response = $mw->process($request, $this->okHandler());
        self::assertNotEmpty($response->getHeaderLine('X-Response-Time-ms'));
    }

    public function testRecordsEventInAnalytics(): void
    {
        $analytics = new SearchAnalytics();
        $mw = new AuditMiddleware($analytics);
        $request = $this->factory->createServerRequest('GET', '/v1/search/web')
            ->withAttribute(AuthMiddleware::ATTR_API_KEY_ID, 'k-1');

        $mw->process($request, $this->okHandler());

        $events = $analytics->events();
        self::assertCount(1, $events);
        self::assertSame('http_request', $events[0]['kind']);
        self::assertSame(200, $events[0]['status']);
        self::assertSame('k-1', $events[0]['apiKeyId']);
    }

    public function testCapturesXForwardedForIp(): void
    {
        $analytics = new SearchAnalytics();
        $mw = new AuditMiddleware($analytics);
        $request = $this->factory->createServerRequest('GET', '/v1/search/web')
            ->withHeader('X-Forwarded-For', '5.6.7.8');

        $mw->process($request, $this->okHandler());
        self::assertSame('5.6.7.8', $analytics->events()[0]['ip']);
    }

    public function testNoAnalyticsIsNoOp(): void
    {
        $mw = new AuditMiddleware();
        $request = $this->factory->createServerRequest('GET', '/v1/search/web');

        $response = $mw->process($request, $this->okHandler());
        self::assertSame(200, $response->getStatusCode());
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
}
