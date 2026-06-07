<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Http\Middleware;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use SearchGateway\Http\Middleware\AuthMiddleware;
use SearchGateway\Http\Middleware\RateLimitMiddleware;

final class RateLimitMiddlewareTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    public function testAllowsRequestsUnderLimit(): void
    {
        $mw = new RateLimitMiddleware(defaultLimit: 3, defaultWindow: 60);
        $request = $this->factory->createServerRequest('GET', '/v1/search/web')
            ->withAttribute(AuthMiddleware::ATTR_API_KEY_ID, 'k-1');

        for ($i = 0; $i < 3; $i++) {
            $response = $mw->process($request, $this->okHandler());
            self::assertSame(200, $response->getStatusCode());
        }
    }

    public function testReturns429WhenLimitExceeded(): void
    {
        $mw = new RateLimitMiddleware(defaultLimit: 1, defaultWindow: 60);
        $request = $this->factory->createServerRequest('GET', '/v1/search/web')
            ->withAttribute(AuthMiddleware::ATTR_API_KEY_ID, 'k-1');

        $first = $mw->process($request, $this->okHandler());
        self::assertSame(200, $first->getStatusCode());

        $second = $mw->process($request, $this->okHandler());
        self::assertSame(429, $second->getStatusCode());
    }

    public function testAddsRateHeadersToResponse(): void
    {
        $mw = new RateLimitMiddleware(defaultLimit: 5, defaultWindow: 60);
        $request = $this->factory->createServerRequest('GET', '/v1/search/web')
            ->withAttribute(AuthMiddleware::ATTR_API_KEY_ID, 'k-1');

        $response = $mw->process($request, $this->okHandler());

        self::assertSame('5', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('4', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    public function testUsesExplicitKeyAttributeOverApiKey(): void
    {
        $mw = new RateLimitMiddleware(defaultLimit: 1, defaultWindow: 60);

        $reqA = $this->factory->createServerRequest('GET', '/a')
            ->withAttribute(RateLimitMiddleware::ATTR_KEY, 'custom-A')
            ->withAttribute(AuthMiddleware::ATTR_API_KEY_ID, 'k-1');
        $reqB = $this->factory->createServerRequest('GET', '/b')
            ->withAttribute(RateLimitMiddleware::ATTR_KEY, 'custom-B')
            ->withAttribute(AuthMiddleware::ATTR_API_KEY_ID, 'k-1');

        $mw->process($reqA, $this->okHandler());
        $response = $mw->process($reqB, $this->okHandler());
        self::assertSame(200, $response->getStatusCode());
    }

    public function testFallsBackToIpWhenNoApiKey(): void
    {
        $mw = new RateLimitMiddleware(defaultLimit: 1, defaultWindow: 60);
        $request = $this->factory->createServerRequest('GET', '/v1/search/web', ['REMOTE_ADDR' => '1.2.3.4']);

        $first = $mw->process($request, $this->okHandler());
        self::assertSame(200, $first->getStatusCode());

        $request2 = $this->factory->createServerRequest('GET', '/v1/search/web', ['REMOTE_ADDR' => '1.2.3.4']);
        $second = $mw->process($request2, $this->okHandler());
        self::assertSame(429, $second->getStatusCode());
    }

    public function testResetClearsAllBuckets(): void
    {
        $mw = new RateLimitMiddleware(defaultLimit: 1, defaultWindow: 60);
        $request = $this->factory->createServerRequest('GET', '/v1/search/web')
            ->withAttribute(AuthMiddleware::ATTR_API_KEY_ID, 'k-1');

        $mw->process($request, $this->okHandler());
        $mw->reset();

        $response = $mw->process($request, $this->okHandler());
        self::assertSame(200, $response->getStatusCode());
    }

    public function testPerRequestLimitOverridesDefault(): void
    {
        $mw = new RateLimitMiddleware(defaultLimit: 5, defaultWindow: 60);
        $request = $this->factory->createServerRequest('GET', '/v1/search/web')
            ->withAttribute(AuthMiddleware::ATTR_API_KEY_ID, 'k-1')
            ->withAttribute(RateLimitMiddleware::ATTR_LIMIT, 1);

        $first = $mw->process($request, $this->okHandler());
        $second = $mw->process($request, $this->okHandler());

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(429, $second->getStatusCode());
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
