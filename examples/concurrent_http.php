<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use SearchGateway\Infrastructure\GuzzleConcurrentHttpClient;
use SearchGateway\Infrastructure\LoggerInterface;

/**
 * Example: parallel HTTP fan-out across 3 search providers via GuzzleHttp\Pool.
 *
 * Run:
 *   php examples/concurrent_http.php
 */

$logger = new class implements LoggerInterface {
    public function debug(string $message, array $context = []): void {}
    public function info(string $message, array $context = []): void {
        echo "[INFO] {$message}\n";
    }
    public function warning(string $message, array $context = []): void {
        echo "[WARN] {$message}\n";
    }
    public function error(string $message, array $context = []): void {
        fwrite(STDERR, "[ERROR] {$message}\n");
    }
};

$guzzle = new Client(['timeout' => 5.0, 'http_errors' => false]);
$client = new GuzzleConcurrentHttpClient($guzzle, $logger, concurrency: 3);

$jobs = [
    'brave' => [
        'method' => 'GET',
        'uri' => 'https://api.search.brave.com/res/v1/web/search?q=php+8.4',
        'headers' => [
            'X-Subscription-Token' => $_ENV['BRAVE_API_KEY'] ?? 'demo',
            'Accept' => 'application/json',
        ],
        'body' => null,
        'provider' => 'brave',
        'decode' => static function (string $raw): array {
            $json = json_decode($raw, true);
            $docs = [];
            foreach ($json['web']['results'] ?? [] as $r) {
                $docs[] = [
                    'url' => $r['url'] ?? '',
                    'title' => $r['title'] ?? '',
                    'passage' => $r['description'] ?? '',
                    'score' => 1.0,
                ];
            }
            return $docs;
        },
    ],
    'duckduckgo' => [
        'method' => 'GET',
        'uri' => 'https://api.duckduckgo.com/?q=php+8.4&format=json',
        'headers' => ['Accept' => 'application/json'],
        'body' => null,
        'provider' => 'duckduckgo',
        'decode' => static fn(string $raw): array => json_decode($raw, true)['RelatedTopics'] ?? [],
    ],
    'wikipedia' => [
        'method' => 'GET',
        'uri' => 'https://en.wikipedia.org/w/api.php?action=opensearch&format=json&search=php+8.4',
        'headers' => ['Accept' => 'application/json'],
        'body' => null,
        'provider' => 'wikipedia',
        'decode' => static fn(string $raw): array => json_decode($raw, true) ?? [],
    ],
];

$start = microtime(true);
$results = $client->runConcurrent($jobs, ['concurrency' => 3]);
$elapsed = (microtime(true) - $start) * 1000;

foreach ($results as $provider => $r) {
    echo sprintf(
        "[%s] success=%s docs=%d latency=%.1fms status=%s\n",
        $provider,
        $r['success'] ? 'yes' : 'no',
        count($r['value']),
        $r['latency_ms'],
        $r['status'] ?? 'n/a',
    );
    if (!$r['success']) {
        echo "  error: {$r['error']}\n";
    }
}

echo sprintf("\nTotal wall time: %.1fms (concurrent)\n", $elapsed);
