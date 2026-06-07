<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use SearchGateway\Builder\GatewayBuilder;
use SearchGateway\Gateway\MockSearchGateway;
use SearchGateway\Infrastructure\CacheInterface;
use SearchGateway\Infrastructure\HttpClientInterface;
use SearchGateway\Infrastructure\LoggerInterface;
use SearchGateway\Infrastructure\MetricsInterface;
use SearchGateway\Infrastructure\PhpRedisClientAdapter;
use SearchGateway\Infrastructure\RateLimiterInterface;
use SearchGateway\Resilience\RedisCircuitBreaker;

/**
 * Example: production-grade gateway with distributed Redis circuit breaker,
 * Guzzle concurrent client, in-memory cache, retry, and metrics.
 *
 * Prerequisite: ext-redis, guzzlehttp/guzzle, redis-server on localhost
 *
 * Run:
 *   php examples/builder_production.php
 */

if (!extension_loaded('redis')) {
    fwrite(STDERR, "ext-redis is not installed.\n");
    exit(1);
}

$logger = new class implements LoggerInterface {
    public function debug(string $message, array $context = []): void {}
    public function info(string $message, array $context = []): void { echo "[INFO] {$message}\n"; }
    public function warning(string $message, array $context = []): void { echo "[WARN] {$message}\n"; }
    public function error(string $message, array $context = []): void { fwrite(STDERR, "[ERROR] {$message}\n"); }
};

$metrics = new class implements MetricsInterface {
    public function timing(string $name, float $seconds): void { echo "[METRIC] {$name}: " . round($seconds * 1000, 1) . "ms\n"; }
    public function increment(string $name, int $count = 1): void { echo "[METRIC] {$name} +{$count}\n"; }
    public function gauge(string $name, float $value): void { echo "[METRIC] {$name} = {$value}\n"; }
};

$cache = new class implements CacheInterface {
    private array $store = [];
    public function get(string $key): mixed { return $this->store[$key] ?? null; }
    public function set(string $key, mixed $value, int $ttlSeconds): bool { $this->store[$key] = $value; return true; }
};

$limiter = new class implements RateLimiterInterface {
    private array $buckets = [];
    public function acquire(string $key, int $maxRequests, int $windowSeconds): bool
    {
        $now = time();
        $this->buckets[$key] = array_filter($this->buckets[$key] ?? [], fn(int $t): bool => $t > $now - $windowSeconds);
        if (count($this->buckets[$key]) < $maxRequests) {
            $this->buckets[$key][] = $now;
            return true;
        }
        return false;
    }
    public function waitTime(string $key, int $maxRequests, int $windowSeconds): float
    {
        $now = time();
        $this->buckets[$key] = array_filter($this->buckets[$key] ?? [], fn(int $t): bool => $t > $now - $windowSeconds);
        if (empty($this->buckets[$key])) return 0.0;
        return (float) (min($this->buckets[$key]) + $windowSeconds - $now);
    }
};

$r = new \Redis();
$r->connect('127.0.0.1', 6379);
$redisAdapter = new PhpRedisClientAdapter($r);

$guzzle = new Client(['timeout' => 5.0, 'http_errors' => false]);

$gateway = (new GatewayBuilder())
    ->addYandex(new \stdClass())
    ->addProvider(new MockSearchGateway())
    ->withCache($cache, 3600)
    ->withRetry(2, 150)
    ->withMetrics($metrics)
    ->withLogger($logger)
    ->withRateLimit($limiter, 'search', max: 100, window: 60)
    ->withRedisCircuitBreaker($redisAdapter, 'search', threshold: 5, timeout: 30)
    ->withGuzzleConcurrentClient($guzzle, $logger, concurrency: 5)
    ->withFallback(new MockSearchGateway())
    ->build();

$docs = $gateway->llmContext('PHP 8.4 features', ['docsOnPage' => 5]);
echo "\nGot " . count($docs) . " documents\n";
