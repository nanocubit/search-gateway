<?php

declare(strict_types=1);

namespace SearchGateway\Tests\LLM;

use PHPUnit\Framework\TestCase;
use SearchGateway\Contract\SearchGatewayException;
use SearchGateway\Infrastructure\HttpClientInterface;
use SearchGateway\LLM\OllamaLLMClient;

final class OllamaLLMClientTest extends TestCase
{
    public function testGenerateCallsApiAndReturnsResponse(): void
    {
        $http = $this->httpReturning([
            'response' => 'Hello, world!',
            'done' => true,
        ]);

        $client = new OllamaLLMClient($http, 'http://localhost:11434', 'llama3.2');
        $out = $client->generate('hi');

        $this->assertSame('Hello, world!', $out);
    }

    public function testGenerateWrapsNetworkError(): void
    {
        $http = $this->httpThrowing(new \RuntimeException('connection refused'));

        $client = new OllamaLLMClient($http);

        $this->expectException(SearchGatewayException::class);
        $client->generate('hi');
    }

    public function testStreamGenerateFallsBackToSingleChunkWithoutGuzzle(): void
    {
        $http = $this->httpReturning(['response' => 'fallback', 'done' => true]);
        $client = new OllamaLLMClient($http);

        $chunks = iterator_to_array($client->streamGenerate('hi'), false);

        $this->assertSame(['fallback'], $chunks);
    }

    public function testOptionsAreForwarded(): void
    {
        /** @var array<string, mixed>|null $captured */
        $captured = null;
        $http = new class ($captured) implements HttpClientInterface {
            /** @phpstan-ignore-next-line property.isUnused */
            public function __construct(private mixed &$captured)
            {
            }
            /**
             * @param array<string, mixed> $options
             */
            public function getJson(string $url, array $options = []): array
            {
                return [];
            }
            /**
             * @param array<string, mixed> $payload
             * @param array<string, mixed> $options
             */
            public function postJson(string $url, array $payload, array $options = []): array
            {
                $this->captured = ['url' => $url, 'payload' => $payload, 'options' => $options];
                return ['response' => 'ok'];
            }
        };

        $client = new OllamaLLMClient($http, 'http://localhost:11434', 'llama3.2');
        $client->generate('hi', ['temperature' => 0.7, 'stop' => ['END']]);

        $this->assertNotNull($captured);
        $this->assertSame('llama3.2', $captured['payload']['model']);
        $this->assertSame(0.7, $captured['payload']['temperature']);
        $this->assertSame(['END'], $captured['payload']['stop']);
        $this->assertFalse($captured['payload']['stream']);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function httpReturning(array $response): HttpClientInterface
    {
        return new class ($response) implements HttpClientInterface {
            /**
             * @param array<string, mixed> $response
             */
            public function __construct(private readonly array $response)
            {
            }
            /**
             * @param array<string, mixed> $options
             */
            public function getJson(string $url, array $options = []): array
            {
                return [];
            }
            /**
             * @param array<string, mixed> $payload
             * @param array<string, mixed> $options
             */
            public function postJson(string $url, array $payload, array $options = []): array
            {
                return $this->response;
            }
        };
    }

    private function httpThrowing(\Throwable $error): HttpClientInterface
    {
        return new class ($error) implements HttpClientInterface {
            public function __construct(private readonly \Throwable $error)
            {
            }
            /**
             * @param array<string, mixed> $options
             */
            public function getJson(string $url, array $options = []): array
            {
                throw $this->error;
            }
            /**
             * @param array<string, mixed> $payload
             * @param array<string, mixed> $options
             */
            public function postJson(string $url, array $payload, array $options = []): array
            {
                throw $this->error;
            }
        };
    }
}
