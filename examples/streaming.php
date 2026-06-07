<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SearchGateway\Gateway\MockSearchGateway;
use SearchGateway\Infrastructure\HttpClientInterface;
use SearchGateway\Infrastructure\LoggerInterface;
use SearchGateway\Streaming\StreamingSearchGateway;

/**
 * Example: streaming a generative search answer.
 *
 * In production, replace MockSearchGateway with Brave/Yandex/Perplexity
 * and use OllamaLLMClient for the LLM (see examples/ollama_streaming.php).
 *
 * Run:
 *   php examples/streaming.php
 */

$gateway = new MockSearchGateway([
    'llmContext' => [
        ['url' => 'https://php.net/84', 'title' => 'PHP 8.4', 'passage' => 'JIT and new features', 'score' => 1.0],
    ],
]);

$llm = new class implements \SearchGateway\Contract\StreamingLLMClientInterface {
    public function generate(string $prompt, array $options = []): string
    {
        return 'PHP 8.4 introduces JIT improvements and new syntax features.';
    }
    public function streamGenerate(string $prompt, array $options = []): \Generator
    {
        foreach (['PHP ', '8.4 ', 'introduces ', 'JIT ', 'improvements.'] as $chunk) {
            yield $chunk;
        }
    }
};

$streamer = new StreamingSearchGateway($gateway, $llm);

$gen = $streamer->streamGen('What is new in PHP 8.4?');
foreach ($gen as $chunk) {
    echo $chunk;
    flush();
}
echo "\n";

$dto = $gen->getReturn();
echo "Sources: " . count($dto->sources) . "\n";
