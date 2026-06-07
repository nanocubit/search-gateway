<?php

declare(strict_types=1);

namespace SearchGateway\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SearchGateway\Http\JsonResponse;

final class CorsMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $allowedOrigins
     * @param list<string> $allowedMethods
     * @param list<string> $allowedHeaders
     */
    public function __construct(
        private readonly array $allowedOrigins = ['*'],
        private readonly array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
        private readonly array $allowedHeaders = ['Content-Type', 'Authorization', 'X-Request-Id'],
        private readonly int $maxAge = 86400,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            return $this->preflight();
        }
        $response = $handler->handle($request);
        return $this->applyHeaders($response);
    }

    public function applyHeaders(ResponseInterface $response): ResponseInterface
    {
        $origins = $this->allowedOrigins === [] ? ['*'] : $this->allowedOrigins;
        return $response
            ->withHeader('Access-Control-Allow-Origin', implode(', ', $origins))
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders))
            ->withHeader('Access-Control-Max-Age', (string) $this->maxAge);
    }

    private function preflight(): ResponseInterface
    {
        $response = new \Nyholm\Psr7\Response();
        $response->getBody()->write('');
        return $this->applyHeaders($response->withStatus(204));
    }
}
