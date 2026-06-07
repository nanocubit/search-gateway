<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Controller;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use PHPUnit\Framework\TestCase;
use SearchGateway\Analytics\SearchAnalytics;
use SearchGateway\Controller\StreamController;
use SearchGateway\Request\SearchRequest;
use SearchGateway\Plugin\PluginPipeline;
use SearchGateway\Router\InMemoryRouteRegistry;
use SearchGateway\Router\Route;
use SearchGateway\Router\RouteResolver;
use SearchGateway\Streaming\SseEmitter;

final class StreamControllerTest extends TestCase
{
    private InMemoryRouteRegistry $registry;
    private ServerRequestCreator $creator;
    private StreamController $controller;

    protected function setUp(): void
    {
        $this->registry = new InMemoryRouteRegistry();
        $factory = new Psr17Factory();
        $this->creator = new ServerRequestCreator($factory, $factory, $factory, $factory);
        $resolver = new RouteResolver();
        $pipeline = new PluginPipeline();
        $analytics = new SearchAnalytics();
        $this->controller = new StreamController(
            $this->registry,
            $resolver,
            $pipeline,
            $analytics,
            new SseEmitter(keepAlive: false),
        );
    }

    public function testReturns404ForUnknownRoute(): void
    {
        $request = $this->creator->fromArrays(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/unknown/stream']);
        $response = $this->controller->handle($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('No streaming route', (string) $response->getBody());
    }

    public function testReturns500WhenRouteHasNoStreamSource(): void
    {
        $this->registry->register(new Route(
            name: 'v1.broken',
            method: Route::METHOD_POST,
            path: '/v1/stream/broken',
            action: 'stream',
        ));
        $request = $this->creator->fromArrays([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/v1/stream/broken',
        ], [], [], [], ['query' => 'hi']);
        $request = $request->withHeader('Content-Type', 'application/json');

        $response = $this->controller->handle($request);

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('no stream source', (string) $response->getBody());
    }

    public function testStreamGeneratorEmitsChunksAsSse(): void
    {
        $chunks = ['first', 'second', 'third'];
        $this->registry->register(new Route(
            name: 'v1.simple',
            method: Route::METHOD_POST,
            path: '/v1/stream/simple',
            action: 'stream',
            config: ['stream_iterable' => $chunks],
        ));

        $request = $this->creator->fromArrays([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/v1/stream/simple',
        ], [], [], [], ['query' => 'tell me a story']);
        $request = $request->withHeader('Content-Type', 'application/json');

        $response = $this->controller->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/event-stream; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $body = (string) $response->getBody();

        self::assertStringContainsString('"text":"first"', $body);
        self::assertStringContainsString('"text":"second"', $body);
        self::assertStringContainsString('"text":"third"', $body);
        self::assertStringContainsString('event: done', $body);
    }

    public function testStreamGeneratorCallableReceivesSearchRequest(): void
    {
        $captured = null;
        $callable = function (mixed $req) use (&$captured): array {
            $captured = $req;
            return ['chunk-A', 'chunk-B'];
        };
        $this->registry->register(new Route(
            name: 'v1.cb',
            method: Route::METHOD_POST,
            path: '/v1/stream/cb',
            action: 'stream',
            config: ['stream_generator' => $callable],
        ));

        $request = $this->creator->fromArrays([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/v1/stream/cb',
        ], [], [], [], ['query' => 'q']);
        $request = $request->withHeader('Content-Type', 'application/json');

        $response = $this->controller->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(SearchRequest::class, $captured);
        self::assertSame('q', $captured->query);
        $body = (string) $response->getBody();
        self::assertStringContainsString('"text":"chunk-A"', $body);
    }

    public function testWrongMethodReturns404(): void
    {
        $this->registry->register(new Route(
            name: 'v1.post-only',
            method: Route::METHOD_POST,
            path: '/v1/stream/x',
            action: 'stream',
            config: ['stream_iterable' => []],
        ));

        $request = $this->creator->fromArrays(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/v1/stream/x']);
        $response = $this->controller->handle($request);

        self::assertSame(404, $response->getStatusCode());
    }
}
