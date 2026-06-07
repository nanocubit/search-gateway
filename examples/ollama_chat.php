<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use SearchGateway\Infrastructure\HttpClientInterface;
use SearchGateway\LLM\OllamaChatLLMClient;

/**
 * Example: multi-turn chat with Ollama /api/chat.
 *
 * Prerequisite: ollama pull llama3.2
 *
 * Run:
 *   php examples/ollama_chat.php
 */

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
            CURLOPT_TIMEOUT => 60,
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

$guzzle = new Client(['timeout' => 60, 'http_errors' => false]);

$chat = new OllamaChatLLMClient(
    $http,
    baseUri: 'http://localhost:11434',
    model: 'llama3.2',
    streamingClient: $guzzle,
);

echo "You: hello!\n";
echo "AI: " . $chat->sendMessage('hello!') . "\n";

echo "You: what is 2+2?\n";
echo "AI: " . $chat->sendMessage('what is 2+2?') . "\n";

echo "You: multiply that by 3\n";
echo "AI (with context): " . $chat->sendMessage('multiply that by 3') . "\n";

echo "\n--- History ---\n";
foreach ($chat->history() as $m) {
    echo sprintf("[%s] %s\n", $m['role'], $m['content']);
}
