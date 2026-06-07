<?php

declare(strict_types=1);

namespace SearchGateway\LLM;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use SearchGateway\Contract\SearchGatewayException;
use SearchGateway\Contract\StreamingLLMClientInterface;
use SearchGateway\Infrastructure\HttpClientInterface;
use SearchGateway\Infrastructure\LoggerInterface;

/**
 * Local LLM client for Ollama (https://ollama.com).
 * Supports /api/generate for both blocking and streaming output.
 *
 * Non-streaming uses the project's HttpClientInterface (any Guzzle/Symfony/curl adapter).
 * Streaming requires a Guzzle client for true token-level NDJSON streaming.
 */
final class OllamaLLMClient implements StreamingLLMClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $baseUri = 'http://localhost:11434',
        private readonly string $model = 'llama3.2',
        private readonly ?Client $streamingClient = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function generate(string $prompt, array $options = []): string
    {
        $payload = $this->buildPayload($prompt, $options, stream: false);

        try {
            $resp = $this->http->postJson(
                rtrim($this->baseUri, '/') . '/api/generate',
                $payload,
                ['headers' => ['Content-Type' => 'application/json']],
            );
        } catch (\Throwable $e) {
            throw new SearchGatewayException(
                'Ollama generate failed: ' . $e->getMessage(),
                502,
                $e,
                'ollama',
            );
        }

        $response = $resp['response'] ?? null;
        return is_string($response) ? $response : '';
    }

    /**
     * @param array<string, mixed> $options
     * @return \Generator<int, string, mixed, void>
     */
    public function streamGenerate(string $prompt, array $options = []): \Generator
    {
        if ($this->streamingClient === null) {
            $this->logger?->warning('OllamaLLMClient: streamingClient not provided, falling back to single-chunk emission');
            yield $this->generate($prompt, $options);
            return;
        }

        $payload = $this->buildPayload($prompt, $options, stream: true);
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        try {
            $response = $this->streamingClient->post(
                rtrim($this->baseUri, '/') . '/api/generate',
                [
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => $body,
                    'stream' => true,
                    'http_errors' => false,
                ],
            );
        } catch (RequestException $e) {
            throw new SearchGatewayException(
                'Ollama stream failed: ' . $e->getMessage(),
                502,
                $e,
                'ollama',
            );
        }

        $status = $response->getStatusCode();
        if ($status >= 400) {
            throw new SearchGatewayException(
                "Ollama stream HTTP {$status}: " . $response->getBody()->getContents(),
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
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildPayload(string $prompt, array $options, bool $stream): array
    {
        $modelRaw = $options['model'] ?? null;
        $model = is_string($modelRaw) ? $modelRaw : $this->model;

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
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
        if (isset($decoded['response']) && is_string($decoded['response'])) {
            return $decoded['response'];
        }
        return null;
    }
}
