<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Http\Middleware;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use SearchGateway\Http\Middleware\JsonBodyMiddleware;

final class JsonBodyMiddlewareTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    public function testPassesGetRequestThroughUnchanged(): void
    {
        $mw = new JsonBodyMiddleware();
        $request = $this->factory->createServerRequest('GET', '/v1/search/web');

        $response = $mw->process($request, $this->okHandler());
        self::assertSame(200, $response->getStatusCode());
    }

    public function testAttachesParsedBodyForValidJson(): void
    {
        $mw = new JsonBodyMiddleware();
        $request = $this->factory->createServerRequest('POST', '/v1/search/web')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream('{"query":"php"}'));

        $handler = new class implements RequestHandlerInterface {
            public mixed $seen = null;

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->seen = $request->getAttribute(JsonBodyMiddleware::ATTR_PARSED);
                return new Response();
            }
        };
        $mw->process($request, $handler);
        self::assertNotNull($handler->seen);
        self::assertIsArray($handler->seen);
        self::assertSame('php', $handler->seen['query']);
    }

    public function testReturns400OnInvalidJson(): void
    {
        $mw = new JsonBodyMiddleware();
        $request = $this->factory->createServerRequest('POST', '/v1/search/web')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream('{not json'));

        $response = $mw->process($request, $this->okHandler());
        self::assertSame(400, $response->getStatusCode());
    }

    public function testReturns400WhenJsonIsNotArray(): void
    {
        $mw = new JsonBodyMiddleware();
        $request = $this->factory->createServerRequest('POST', '/v1/search/web')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream('"just a string"'));

        $response = $mw->process($request, $this->okHandler());
        self::assertSame(400, $response->getStatusCode());
    }

    public function testEmptyBodyYieldsEmptyArray(): void
    {
        $mw = new JsonBodyMiddleware();
        $request = $this->factory->createServerRequest('POST', '/v1/search/web')
            ->withHeader('Content-Type', 'application/json');

        $handler = new class implements RequestHandlerInterface {
            public mixed $seen = 'untouched';

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->seen = $request->getAttribute(JsonBodyMiddleware::ATTR_PARSED);
                return new Response();
            }
        };
        $mw->process($request, $handler);
        self::assertSame([], $handler->seen);
    }

    public function testNonJsonContentTypeIsPassedThrough(): void
    {
        $mw = new JsonBodyMiddleware();
        $request = $this->factory->createServerRequest('POST', '/v1/search/web')
            ->withHeader('Content-Type', 'text/plain')
            ->withBody($this->factory->createStream('hello'));

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
