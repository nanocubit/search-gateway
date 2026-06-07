<?php

return [
    'yandex' => [
        'enabled' => env('YANDEX_SEARCH_ENABLED', false),
        'client_class' => env('YANDEX_SEARCH_CLIENT_CLASS', \YandexSearch\Client::class),
    ],
    'brave' => [
        'enabled' => env('BRAVE_SEARCH_ENABLED', false),
        'api_key' => env('BRAVE_API_KEY', ''),
        'base_uri' => env('BRAVE_BASE_URI', 'https://api.search.brave.com/res/v1'),
    ],
    'cache' => [
        'enabled' => env('SEARCH_CACHE_ENABLED', true),
        'ttl' => env('SEARCH_CACHE_TTL', 3600),
    ],
    'retry' => [
        'enabled' => env('SEARCH_RETRY_ENABLED', true),
        'retries' => 2,
        'delay_ms' => 150,
    ],
    'metrics' => [
        'enabled' => env('SEARCH_METRICS_ENABLED', false),
    ],
    'circuit_breaker' => [
        'driver' => env('SEARCH_CIRCUIT_BREAKER_DRIVER', 'memory'),
        'name' => env('SEARCH_CIRCUIT_BREAKER_NAME', 'search'),
        'threshold' => (int) env('SEARCH_CIRCUIT_BREAKER_THRESHOLD', 5),
        'timeout' => (int) env('SEARCH_CIRCUIT_BREAKER_TIMEOUT', 30),
        'half_open_max_calls' => (int) env('SEARCH_CIRCUIT_BREAKER_HALF_OPEN_MAX', 3),
        'redis_client' => env('SEARCH_CIRCUIT_BREAKER_REDIS', 'phpredis'),
    ],
    'async' => [
        'enabled' => env('SEARCH_ASYNC_ENABLED', false),
        'driver' => env('SEARCH_ASYNC_DRIVER', 'guzzle'),
        'concurrency' => (int) env('SEARCH_ASYNC_CONCURRENCY', 5),
        'timeout' => (float) env('SEARCH_ASYNC_TIMEOUT', 5.0),
    ],
    'ollama' => [
        'enabled' => env('OLLAMA_ENABLED', false),
        'base_uri' => env('OLLAMA_BASE_URI', 'http://localhost:11434'),
        'model' => env('OLLAMA_MODEL', 'llama3.2'),
        'system_prompt' => env(
            'OLLAMA_SYSTEM_PROMPT',
            'You are an AI search assistant. Answer the user question using only the provided sources. Cite sources by number.',
        ),
    ],
];
