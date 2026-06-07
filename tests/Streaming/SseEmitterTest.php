<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Streaming;

use PHPUnit\Framework\TestCase;
use SearchGateway\Streaming\SseEmitter;

final class SseEmitterTest extends TestCase
{
    private SseEmitter $emitter;

    protected function setUp(): void
    {
        $this->emitter = new SseEmitter(keepAlive: false);
    }

    public function testEmitSetsSseHeaders(): void
    {
        $response = $this->emitter->emit(['hello']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/event-stream; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('no-cache', $response->getHeaderLine('Cache-Control'));
        self::assertSame('keep-alive', $response->getHeaderLine('Connection'));
    }

    public function testEmitWrapsStringChunkInIndexedEvent(): void
    {
        $response = $this->emitter->emit(['hello world']);
        $body = (string) $response->getBody();

        self::assertStringContainsString('event: chunk', $body);
        self::assertStringContainsString('"text":"hello world"', $body);
        self::assertStringContainsString('"index":0', $body);
        self::assertStringContainsString('event: done', $body);
    }

    public function testEmitPreservesArrayPayloadShape(): void
    {
        $response = $this->emitter->emit([['text' => 'first', 'extra' => 'meta']]);
        $body = (string) $response->getBody();

        self::assertStringContainsString('"text":"first"', $body);
        self::assertStringContainsString('"extra":"meta"', $body);
    }

    public function testEmitWritesMultipleChunksInOrder(): void
    {
        $response = $this->emitter->emit(['one', 'two', 'three']);
        $body = (string) $response->getBody();

        $posOne = strpos($body, '"text":"one"');
        $posTwo = strpos($body, '"text":"two"');
        $posThree = strpos($body, '"text":"three"');
        $posDone = strpos($body, 'event: done');

        self::assertIsInt($posOne);
        self::assertIsInt($posTwo);
        self::assertIsInt($posThree);
        self::assertIsInt($posDone);
        self::assertLessThan($posTwo, $posOne);
        self::assertLessThan($posThree, $posTwo);
        self::assertLessThan($posDone, $posThree);
    }

    public function testFormatEventProducesValidSseFrame(): void
    {
        $frame = $this->emitter->formatEvent('custom', ['k' => 'v']);

        self::assertStringStartsWith("event: custom\n", $frame);
        self::assertStringContainsString("data: ", $frame);
        self::assertStringContainsString('"k":"v"', $frame);
    }

    public function testErrorReturnsNon200StatusWithErrorEvent(): void
    {
        $response = $this->emitter->error('boom', 503);
        $body = (string) $response->getBody();

        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('event: error', $body);
        self::assertStringContainsString('"message":"boom"', $body);
        self::assertStringContainsString('"ok":false', $body);
    }

    public function testEmitWithEmptyIterableStillEmitsDone(): void
    {
        $response = $this->emitter->emit([]);
        $body = (string) $response->getBody();

        self::assertStringContainsString('event: done', $body);
        self::assertStringContainsString('"ok":true', $body);
    }
}
