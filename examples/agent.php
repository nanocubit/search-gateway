<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SearchGateway\Agent\AgentWorkflow;
use SearchGateway\Agent\PersonalSearchContext;
use SearchGateway\Decorator\LLMAnswerSearchGatewayDecorator;
use SearchGateway\Gateway\YandexCloudSearchGateway;
use SearchGateway\Infrastructure\HttpClientInterface;
use SearchGateway\LLM\OllamaLLMClient;
use SearchGateway\Tool\SearchTool;

/**
 * Example: agent workflow with Ollama-powered answer synthesis.
 *
 * Prerequisite: ollama pull llama3.2
 *
 * Run:
 *   php examples/agent.php
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

$llm = new OllamaLLMClient(
    $http,
    baseUri: 'http://localhost:11434',
    model: 'llama3.2',
);

$yandex = new YandexCloudSearchGateway(new \stdClass());
$gateway = new LLMAnswerSearchGatewayDecorator(
    $yandex,
    $llm,
    systemPrompt: 'You are a senior PHP architect. Answer concisely and cite sources by number.',
);

$personal = new PersonalSearchContext();
$personal->setProfile('location', 'Moscow');
$personal->setProfile('language', 'ru');

$agent = new AgentWorkflow(new SearchTool($gateway), $llm, $personal);

$agent->addStep(function (array $ctx, AgentWorkflow $workflow): array {
    if (str_contains($ctx['task'], 'benchmark')) {
        $ctx['extra'] = $workflow->searchTool()->web($ctx['task'] . ' performance numbers')[0]['passage'] ?? '';
    }
    return $ctx;
});

$answer = $agent->run('Сравни производительность PHP 8.3 и 8.4 в реальных бенчмарках');
echo $answer . "\n";
