<?php

declare(strict_types=1);

namespace SearchGateway\Controller;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SearchGateway\Observability\PrometheusExporter;

final class MetricsController implements RequestHandlerInterface
{
    private Psr17Factory $factory;

    public function __construct(private readonly PrometheusExporter $exporter)
    {
        $this->factory = new Psr17Factory();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->exporter->export();
        $response = $this->factory->createResponse(200);
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', $this->exporter->contentType());
    }
}
