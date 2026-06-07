<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Gateway;

use PHPUnit\Framework\TestCase;
use SearchGateway\Contract\SearchGatewayException;
use SearchGateway\Gateway\BraveSearchGateway;
use SearchGateway\Infrastructure\HttpClientInterface;

final class BraveSearchGatewayTest extends TestCase
{
    public function testSearchWebReturnsNormalisedList(): void
    {
        $http = new class implements HttpClientInterface {
            public function getJson(string $url, array $options = []): array
            {
                return [
                    'results' => [
                        ['url' => 'https://example.com', 'title' => 'Example', 'description' => 'Desc', 'score' => 0.9],
                    ],
                ];
            }
            public function postJson(string $url, array $payload, array $options = []): array
            {
                return [];
            }
        };

        $gateway = new BraveSearchGateway($http, 'key');
        $result = $gateway->searchWeb('test');

        $this->assertCount(1, $result);
        $this->assertSame('Example', $result[0]['title']);
        $this->assertSame('web', $result[0]['type']);
    }

    public function testLlmContextReturnsChunks(): void
    {
        $http = new class implements HttpClientInterface {
            public function getJson(string $url, array $options = []): array
            {
                return [
                    'results' => [
                        ['url' => 'https://example.com', 'title' => 'Example', 'description' => 'Desc'],
                    ],
                ];
            }
            public function postJson(string $url, array $payload, array $options = []): array
            {
                return [];
            }
        };

        $gateway = new BraveSearchGateway($http, 'key');
        $ctx = $gateway->llmContext('test');

        $this->assertCount(1, $ctx);
        $this->assertArrayHasKey('domain', $ctx[0]);
        $this->assertSame('example.com', $ctx[0]['domain']);
    }
}
