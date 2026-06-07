<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Request;

use PHPUnit\Framework\TestCase;
use SearchGateway\Request\SearchRequest;

final class SearchRequestTest extends TestCase
{
    public function testStoresAllArguments(): void
    {
        $req = new SearchRequest(
            query: 'php',
            providers: ['yandex'],
            llm: ['driver' => 'ollama', 'model' => 'llama3'],
            stream: true,
            filters: ['language' => 'ru'],
            guardrails: ['noPii'],
            userContext: ['userId' => 'u-1'],
            pathParams: ['version' => '1'],
            apiKeyId: 'key-1',
            routeName: 'v1.web',
        );

        self::assertSame('php', $req->query);
        self::assertSame(['yandex'], $req->providers);
        self::assertSame('ollama', $req->llmDriver());
        self::assertSame('llama3', $req->llmModel());
        self::assertTrue($req->stream);
        self::assertSame(['language' => 'ru'], $req->filters);
        self::assertSame(['noPii'], $req->guardrails);
        self::assertSame(['userId' => 'u-1'], $req->userContext);
        self::assertSame(['version' => '1'], $req->pathParams);
        self::assertSame('key-1', $req->apiKeyId);
        self::assertSame('v1.web', $req->routeName);
    }

    public function testWithUserContextReturnsNewInstanceWithMergedContext(): void
    {
        $req = new SearchRequest(query: 'q', userContext: ['a' => 1]);
        $next = $req->withUserContext('b', 2);

        self::assertNotSame($req, $next);
        self::assertSame(['a' => 1], $req->userContext);
        self::assertSame(['a' => 1, 'b' => 2], $next->userContext);
    }

    public function testWithUserContextOverridesExistingKey(): void
    {
        $req = new SearchRequest(query: 'q', userContext: ['a' => 1]);
        $next = $req->withUserContext('a', 99);

        self::assertSame(['a' => 99], $next->userContext);
    }

    public function testLlmHelpersReturnNullWhenAbsent(): void
    {
        $req = new SearchRequest(query: 'q');
        self::assertNull($req->llmDriver());
        self::assertNull($req->llmModel());
    }

    public function testLlmHelpersCoerceNonScalarsToNull(): void
    {
        $req = new SearchRequest(query: 'q', llm: ['driver' => ['nested']]);
        self::assertNull($req->llmDriver());
    }

    public function testDefaultsAreEmpty(): void
    {
        $req = new SearchRequest(query: 'q');
        self::assertSame([], $req->providers);
        self::assertSame([], $req->llm);
        self::assertFalse($req->stream);
        self::assertSame([], $req->filters);
        self::assertSame([], $req->guardrails);
        self::assertSame([], $req->userContext);
        self::assertSame([], $req->pathParams);
        self::assertNull($req->apiKeyId);
        self::assertSame('', $req->routeName);
    }
}
