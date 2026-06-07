<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SearchGateway\Builder\GatewayBuilder;
use SearchGateway\Contract\CircuitBreakerInterface;
use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Gateway\MockSearchGateway;
use SearchGateway\Infrastructure\CacheInterface;
use SearchGateway\Infrastructure\MetricsInterface;
use SearchGateway\Infrastructure\RateLimiterInterface;
use SearchGateway\Resilience\InMemoryCircuitBreaker;

/**
 * Example: production-grade gateway built with the fluent builder.
 *
 * Run:
 *   php examples/builder.php
 */

$cache = new class implements CacheInterface {
    private array $store = [];
    public function get(string $key): mixed { return $this->store[$key] ?? null; }
    public function set(string $key, mixed $value, int $ttlSeconds): bool { $this->store[$key] = $value; return true; }
};

$metrics = new class implements MetricsInterface {
    public function timing(string $name, float $seconds): void { echo "[METRIC] {$name}: " . round($seconds * 1000, 1) . "ms\n"; }
    public function increment(string $name, int $count = 1): void { echo "[METRIC] {$name} +{$count}\n"; }
    public function gauge(string $name, float $value): void { echo "[METRIC] {$name} = {$value}\n"; }
};

$limiter = new class implements RateLimiterInterface {
    private array $buckets = [];
    public function acquire(string $key, int $maxRequests, int $windowSeconds): bool {
        $now = time();
        $this->buckets[$key] = array_filter($this->buckets[$key] ?? [], fn(int $t): bool => $t > $now - $windowSeconds);
        if (count($this->buckets[$key]) < $maxRequests) {
            $this->buckets[$key][] = $now;
            return true;
        }
        return false;
    }
    public function waitTime(string $key, int $maxRequests, int $windowSeconds): float {
        $now = time();
        $this->buckets[$key] = array_filter($this->buckets[$key] ?? [], fn(int $t): bool => $t > $now - $windowSeconds);
        if (empty($this->buckets[$key])) return 0.0;
        return (float) (min($this->buckets[$key]) + $windowSeconds - $now);
    }
};

$breaker = new InMemoryCircuitBreaker('yandex', failureThreshold: 5, recoveryTimeoutSeconds: 30, halfOpenMaxCalls: 3);

$gateway = (new GatewayBuilder())
    ->addYandex(new \stdClass())
    ->withCache($cache, 3600)
    ->withRetry(2, 150)
    ->withMetrics($metrics)
    ->withCircuitBreakerInterface($breaker)
    ->withRateLimit($limiter, 'yandex', max: 100, window: 60)
    ->withFallback(new MockSearchGateway())
    ->build();

$docs = $gateway->llmContext('PHP 8.4 features', ['docsOnPage' => 5]);
echo "Got " . count($docs) . " documents\n";
