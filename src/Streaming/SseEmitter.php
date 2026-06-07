<?php

declare(strict_types=1);

namespace SearchGateway\Streaming;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;

final class SseEmitter
{
    public const EVENT_CHUNK = 'chunk';
    public const EVENT_ERROR = 'error';
    public const EVENT_DONE = 'done';

    private Psr17Factory $factory;

    public function __construct(private readonly bool $keepAlive = true, private readonly int $keepAliveSeconds = 15)
    {
        $this->factory = new Psr17Factory();
    }

    /**
     * @param iterable<int, string|array<string, mixed>> $chunks
     */
    public function emit(iterable $chunks, ?int $status = 200): ResponseInterface
    {
        $response = $this->factory->createResponse($status ?? 200);
        $response = $response
            ->withHeader('Content-Type', 'text/event-stream; charset=utf-8')
            ->withHeader('Cache-Control', 'no-cache, no-transform')
            ->withHeader('Connection', 'keep-alive')
            ->withHeader('X-Accel-Buffering', 'no');

        $body = $response->getBody();
        $body->write(": stream start\n\n");
        $index = 0;
        $lastWriteAt = time();

        foreach ($chunks as $chunk) {
            if (is_string($chunk)) {
                $payload = ['index' => $index++, 'text' => $chunk];
            } else {
                $payload = $chunk + ['index' => $index++];
            }
            $body->write($this->formatEvent(self::EVENT_CHUNK, $payload));
            $body->write("\n");
            if ($this->keepAlive && (time() - $lastWriteAt) >= $this->keepAliveSeconds) {
                $body->write(": keep-alive\n\n");
                $lastWriteAt = time();
            }
        }

        $body->write($this->formatEvent(self::EVENT_DONE, ['ok' => true]));
        $body->write("\n");

        return $response;
    }

    public function error(string $message, int $status = 500): ResponseInterface
    {
        $response = $this->factory->createResponse($status);
        $response = $response->withHeader('Content-Type', 'text/event-stream; charset=utf-8');
        $response->getBody()->write($this->formatEvent(self::EVENT_ERROR, ['ok' => false, 'message' => $message]));
        $response->getBody()->write($this->formatEvent(self::EVENT_DONE, ['ok' => false]));
        $response->getBody()->write("\n");
        return $response;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function formatEvent(string $event, array $data): string
    {
        $json = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return "event: {$event}\ndata: {$json}\n";
    }
}
