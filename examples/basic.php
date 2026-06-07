<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SearchGateway\Gateway\BraveSearchGateway;
use SearchGateway\Gateway\YandexCloudSearchGateway;
use SearchGateway\Decorator\CachedSearchGatewayDecorator;
use SearchGateway\Decorator\MetricsSearchGatewayDecorator;
use SearchGateway\Decorator\RetryingSearchGatewayDecorator;
use SearchGateway\Tool\MultiSearchGateway;
use SearchGateway\Tool\SearchTool;

// 1. Instantiate raw clients (pseudo-code for Yandex)
$yandexClient = new YandexCloudSearchGateway($yandexSdkClient);

// 2. Wrap with decorators (Perplexity-style resilience)
$yandex = new MetricsSearchGatewayDecorator(
    new RetryingSearchGatewayDecorator(
        new CachedSearchGatewayDecorator($yandexClient, $redisCache),
        retries: 2,
        delayMs: 200
    ),
    $statsdMetrics
);

// 3. Add Brave as secondary provider
$brave = new BraveSearchGateway($guzzleHttp, $_ENV['BRAVE_API_KEY']);

// 4. Multi-provider RAG engine (Perplexity hybrid)
$multi = new MultiSearchGateway([$yandex, $brave]);

// 5. High-level tool
$tool = new SearchTool($multi);

// 6. Use
$docs = $tool->context('PHP 8.4 features');
echo $tool->formatDocs($docs, limit: 3);
