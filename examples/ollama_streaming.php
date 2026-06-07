<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use SearchGateway\Infrastructure\HttpClientInterface;
use SearchGateway\Infrastructure\LoggerInterface;
use SearchGateway\LLM\OllamaLLMClient;
use SearchGateway\Streaming\StreamingSearchGateway;
use SearchGateway\Tool\SearchTool;

/**
 * Example: streaming search answer with a local Ollama LLM.
 *
 * Prerequisite: install Ollama (https://ollama.com) and pull a model:
 *   ollama pull llama3.2
 *
 * Run:
 *   php examples/ollama_streaming.php
 */

// 1. HTTP client (any implementation). Here we use a Guzzle-backed stub.
$http = new class implements HttpClientInterface {
    public function getJson(string $url, array $options = []): array
    {
        return [];
    }
    public function postJson(string $url, array $payload, array $options = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT => 120,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Ollama error: ' . $err);
        }
        curl_close($ch);
        return json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
    }
};

$logger = new class implements LoggerInterface {
    public function debug(string $message, array $context = []): void {}
    public function info(string $message, array $context = []): void {}
    public function warning(string $message, array $context = []): void {}
    public function error(string $message, array $context = []): void {
        fwrite(STDERR, "[ERROR] {$message}\n");
    }
};

// 2. Streaming-capable LLM (Guzzle is required for true token-level streaming)
$guzzle = new Client(['timeout' => 120, 'http_errors' => false]);
$llm = new OllamaLLMClient(
    $http,
    baseUri: 'http://localhost:11434',
    model: 'llama3.2',
    streamingClient: $guzzle,
    logger: $logger,
);

// 3. Search tool (using Mock for offline; replace with Brave/Yandex/Perplexity)
$tool = new SearchTool(new \SearchGateway\Gateway\MockSearchGateway([
    'llmContext' => [
        ['url' => 'https://php.net/84', 'title' => 'PHP 8.4', 'passage' => 'JIT improvements and new syntax.', 'score' => 1.0],
    ],
]));

// 4. Streaming gateway
$streamer = new StreamingSearchGateway(
    $tool->search() ?? throw new \LogicException('tool must hold a gateway'),
    $llm,
);

// 5. Stream output
$gen = $streamer->streamGen('What is new in PHP 8.4?');
foreach ($gen as $chunk) {
    echo $chunk;
    flush();
}
echo "\n";

$final = $gen->getReturn();
echo "\n--- Sources ---\n";
foreach ($final->sources as $i => $s) {
    echo sprintf("[%d] %s — %s\n", $i + 1, $s['title'] ?? '', $s['url'] ?? '');
}
