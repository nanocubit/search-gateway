<?php

declare(strict_types=1);

namespace SearchGateway\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SearchGateway\Http\JsonResponse;

final class JsonBodyMiddleware implements MiddlewareInterface
{
    public const ATTR_PARSED = 'sgw.parsedBody';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        if (!in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            return $handler->handle($request);
        }

        $contentType = $request->getHeaderLine('Content-Type');
        if (!str_contains($contentType, 'application/json')) {
            return $handler->handle($request);
        }

        $raw = (string) $request->getBody();
        if ($raw === '') {
            $request = $request->withAttribute(self::ATTR_PARSED, []);
            return $handler->handle($request);
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return JsonResponse::create(new \Nyholm\Psr7\Response(), 400, [
                'ok' => false,
                'error' => 'Invalid JSON body: ' . $e->getMessage(),
            ]);
        }

        if (!is_array($decoded)) {
            return JsonResponse::create(new \Nyholm\Psr7\Response(), 400, [
                'ok' => false,
                'error' => 'JSON body must be an object/array',
            ]);
        }

        $request = $request->withAttribute(self::ATTR_PARSED, $decoded);
        return $handler->handle($request);
    }
}
