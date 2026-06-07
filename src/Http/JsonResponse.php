<?php

declare(strict_types=1);

namespace SearchGateway\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class JsonResponse
{
    public static function create(
        \Psr\Http\Message\ResponseInterface $base,
        int $status,
        mixed $body,
    ): ResponseInterface {
        $factory = new Psr17Factory();
        $payload = is_string($body) ? $body : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response = $base->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
        return $response->withBody(self::stream($factory, $payload));
    }

    public static function stream(Psr17Factory $factory, string $payload): StreamInterface
    {
        return $factory->createStream($payload);
    }
}
