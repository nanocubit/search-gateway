<?php

declare(strict_types=1);

namespace SearchGateway\LLM;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use SearchGateway\Contract\SearchGatewayException;
use SearchGateway\Contract\StreamingLLMClientInterface;
use SearchGateway\Infrastructure\HttpClientInterface;

/**
 * Multi-turn chat client for Ollama /api/chat endpoint.
 * Manages a rolling message history and exposes generate()/streamGenerate() over it.
 *
 * @api Streaming requires guzzlehttp/guzzle ^7.0
 */
final class OllamaChatLLMClient implements StreamingLLMClientInterface
{
    /** @var list<array{role: string, content: string}> */
    private array $messages = [];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $baseUri = 'http://localhost:11434',
        private readonly string $model = 'llama3.2',
        private readonly ?Client $streamingClient = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function sendMessage(string $userMessage, array $options = []): string
    {
        $this->messages[] = ['role' => 'user', 'content' => $userMessage];
        $reply = $this->callChat($this->messages, $options, stream: false);
        $this->messages[] = ['role' => 'assistant', 'content' => $reply];
        return $reply;
    }

    /**
     * @param array<string, mixed> $options
     * @return \Generator<int, string, mixed, void>
     */
    public function streamMessage(string $userMessage, array $options = []): \Generator
    {
        if ($this->streamingClient === null) {
            yield $this->sendMessage($userMessage, $options);
            return;
        }

        $this->messages[] = ['role' => 'user', 'content' => $userMessage];
        $buffer = '';
        foreach ($this->streamChat($this->messages, $options) as $chunk) {
            $buffer .= $chunk;
            yield $chunk;
        }
        $this->messages[] = ['role' => 'assistant', 'content' => $buffer];
    }

    public function reset(): void
    {
        $this->messages = [];
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    public function history(): array
    {
        return $this->messages;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function generate(string $prompt, array $options = []): string
    {
        return $this->sendMessage($prompt, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return \Generator<int, string, mixed, void>
     */
    public function streamGenerate(string $prompt, array $options = []): \Generator
    {
        yield from $this->streamMessage($prompt, $options);
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     */
    private function callChat(array $messages, array $options, bool $stream): string
    {
        $payload = $this->buildChatPayload($messages, $options, $stream);
        try {
            $resp = $this->http->postJson(
                rtrim($this->baseUri, '/') . '/api/chat',
                $payload,
                ['headers' => ['Content-Type' => 'application/json']],
            );
        } catch (\Throwable $e) {
            throw new SearchGatewayException(
                'Ollama chat failed: ' . $e->getMessage(),
                502,
                $e,
                'ollama',
            );
        }
        $message = $resp['message'] ?? null;
        if (is_array($message) && isset($message['content']) && is_string($message['content'])) {
            return $message['content'];
        }
        return '';
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     * @return \Generator<int, string, mixed, void>
     */
    private function streamChat(array $messages, array $options): \Generator
    {
        $client = $this->streamingClient;
        if ($client === null) {
            throw new SearchGatewayException(
                'OllamaChatLLMClient: streamingClient is required for streaming.',
                500,
                null,
                'ollama',
            );
        }

        $payload = $this->buildChatPayload($messages, $options, stream: true);
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        try {
            $response = $client->post(
                rtrim($this->baseUri, '/') . '/api/chat',
                [
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => $body,
                    'stream' => true,
                    'http_errors' => false,
                ],
            );
        } catch (RequestException $e) {
            throw new SearchGatewayException(
                'Ollama chat stream failed: ' . $e->getMessage(),
                502,
                $e,
                'ollama',
            );
        }

        $status = $response->getStatusCode();
        if ($status >= 400) {
            throw new SearchGatewayException(
                "Ollama chat stream HTTP {$status}: " . $response->getBody()->getContents(),
                $status,
                null,
                'ollama',
            );
        }

        $buffer = '';
        while (!$response->getBody()->eof()) {
            $chunk = $response->getBody()->read(1024);
            if ($chunk === '') {
                continue;
            }
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $piece = $this->parseLine($line);
                if ($piece !== null) {
                    yield $piece;
                }
            }
        }
        $tail = trim($buffer);
        if ($tail !== '') {
            $piece = $this->parseLine($tail);
            if ($piece !== null) {
                yield $piece;
            }
        }
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildChatPayload(array $messages, array $options, bool $stream): array
    {
        $modelRaw = $options['model'] ?? null;
        $model = is_string($modelRaw) ? $modelRaw : $this->model;

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => $stream,
        ];
        foreach (['temperature', 'top_p', 'top_k', 'num_ctx', 'num_predict', 'stop', 'seed', 'format'] as $key) {
            if (array_key_exists($key, $options)) {
                $payload[$key] = $options[$key];
            }
        }
        return $payload;
    }

    private function parseLine(string $line): ?string
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            return null;
        }
        if (isset($decoded['error']) && is_string($decoded['error'])) {
            throw new SearchGatewayException(
                'Ollama error: ' . $decoded['error'],
                502,
                null,
                'ollama',
            );
        }
        $message = $decoded['message'] ?? null;
        if (is_array($message) && isset($message['content']) && is_string($message['content'])) {
            return $message['content'];
        }
        return null;
    }
}
