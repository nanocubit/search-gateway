<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Request;

use PHPUnit\Framework\TestCase;
use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Request\SearchResponse;

final class SearchResponseTest extends TestCase
{
    public function testOkFactoryBuildsSuccessResponse(): void
    {
        $dto = new GenerativeSearchResultDTO(answer: '42');
        $resp = SearchResponse::ok('searchGen', 'v1.gen', $dto, ['latency_ms' => 12.3]);

        self::assertTrue($resp->isOk());
        self::assertSame(200, $resp->status);
        self::assertSame('searchGen', $resp->action);
        self::assertSame('v1.gen', $resp->routeName);
        self::assertSame($dto, $resp->payload);
        self::assertTrue($resp->meta['ok']);
        self::assertSame(12.3, $resp->meta['latency_ms']);
    }

    public function testErrorFactoryBuildsFailureResponse(): void
    {
        $resp = SearchResponse::error('searchWeb', 'v1.web', 'boom', 500, ['trace' => 'x']);

        self::assertFalse($resp->isOk());
        self::assertSame(500, $resp->status);
        self::assertNull($resp->payload);
        self::assertFalse($resp->meta['ok']);
        self::assertSame('boom', $resp->meta['error']);
        self::assertSame('x', $resp->meta['trace']);
    }

    public function testIsOkHandles2xxRange(): void
    {
        self::assertTrue((new SearchResponse(action: 'a', routeName: 'r', status: 200))->isOk());
        self::assertTrue((new SearchResponse(action: 'a', routeName: 'r', status: 201))->isOk());
        self::assertTrue((new SearchResponse(action: 'a', routeName: 'r', status: 299))->isOk());
        self::assertFalse((new SearchResponse(action: 'a', routeName: 'r', status: 300))->isOk());
        self::assertFalse((new SearchResponse(action: 'a', routeName: 'r', status: 400))->isOk());
        self::assertFalse((new SearchResponse(action: 'a', routeName: 'r', status: 500))->isOk());
    }

    public function testToArraySerialisesGenerativeDtoToAnswerAndSources(): void
    {
        $dto = new GenerativeSearchResultDTO(
            answer: 'A',
            sources: [['url' => 'https://x', 'title' => 't']],
        );
        $resp = SearchResponse::ok('searchGen', 'v1.gen', $dto);
        $arr = $resp->toArray();
        $payload = $resp->payload;

        self::assertInstanceOf(GenerativeSearchResultDTO::class, $payload);
        self::assertSame('A', $payload->answer);
        self::assertSame([['url' => 'https://x', 'title' => 't']], $payload->sources);
        self::assertSame('searchGen', $arr['action']);
        self::assertSame('v1.gen', $arr['route']);
        self::assertTrue($arr['ok']);
        self::assertSame(200, $arr['status']);
    }

    public function testToArraySerialisesArrayPayloadAsIs(): void
    {
        $resp = SearchResponse::ok('searchWeb', 'v1.web', [['url' => 'u', 'title' => 't']]);
        $arr = $resp->toArray();

        self::assertSame([['url' => 'u', 'title' => 't']], $arr['payload']);
    }

    public function testToArraySerialisesNullPayload(): void
    {
        $resp = SearchResponse::error('searchWeb', 'v1.web', 'x', 500);
        $arr = $resp->toArray();

        self::assertNull($arr['payload']);
    }
}
