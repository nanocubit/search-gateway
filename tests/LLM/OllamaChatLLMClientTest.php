<?php

declare(strict_types=1);

namespace SearchGateway\Tests\LLM;

use PHPUnit\Framework\TestCase;
use SearchGateway\Infrastructure\HttpClientInterface;
use SearchGateway\LLM\OllamaChatLLMClient;

final class OllamaChatLLMClientTest extends TestCase
{
    public function testSendMessageAppendsHistory(): void
    {
        $http = $this->httpReturning([
            'message' => ['role' => 'assistant', 'content' => 'reply-1'],
        ]);
        $client = new OllamaChatLLMClient($http, 'http://localhost:11434', 'llama3.2');

        $reply = $client->sendMessage('hello');
        $this->assertSame('reply-1', $reply);

        $history = $client->history();
        $this->assertCount(2, $history);
        $this->assertSame('user', $history[0]['role']);
        $this->assertSame('hello', $history[0]['content']);
        $this->assertSame('assistant', $history[1]['role']);
        $this->assertSame('reply-1', $history[1]['content']);
    }

    public function testResetClearsHistory(): void
    {
        $http = $this->httpReturning(['message' => ['content' => 'r']]);
        $client = new OllamaChatLLMClient($http);

        $client->sendMessage('hi');
        $client->reset();

        $this->assertSame([], $client->history());
    }

    public function testGenerateDelegatesToSendMessage(): void
    {
        $http = $this->httpReturning(['message' => ['content' => 'r2']]);
        $client = new OllamaChatLLMClient($http);

        $this->assertSame('r2', $client->generate('q'));
    }

    public function testStreamGenerateFallsBackToSingleChunk(): void
    {
        $http = $this->httpReturning(['message' => ['content' => 'r3']]);
        $client = new OllamaChatLLMClient($http);

        $chunks = iterator_to_array($client->streamGenerate('q'), false);
        $this->assertSame(['r3'], $chunks);
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
}
