<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Controller;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use PHPUnit\Framework\TestCase;
use SearchGateway\Controller\MetricsController;
use SearchGateway\Infrastructure\InMemoryMetrics;
use SearchGateway\Observability\PrometheusExporter;

final class MetricsControllerTest extends TestCase
{
    public function testReturnsPrometheusContentType(): void
    {
        $controller = new MetricsController(new PrometheusExporter(new InMemoryMetrics()));
        $factory = new Psr17Factory();
        $creator = new ServerRequestCreator($factory, $factory, $factory, $factory);
        $request = $creator->fromArrays(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/metrics']);
        $response = $controller->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/plain', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('version=0.0.4', $response->getHeaderLine('Content-Type'));
    }

    public function testReturnsMetricsBody(): void
    {
        $metrics = new InMemoryMetrics();
        $metrics->increment('http_requests_total', 7);

        $controller = new MetricsController(new PrometheusExporter($metrics));
        $factory = new Psr17Factory();
        $creator = new ServerRequestCreator($factory, $factory, $factory, $factory);
        $request = $creator->fromArrays(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/metrics']);
        $response = $controller->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('http_requests_total 7', $body);
    }
}
