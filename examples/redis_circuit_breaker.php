<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SearchGateway\Infrastructure\PhpRedisClientAdapter;
use SearchGateway\Resilience\RedisCircuitBreaker;

/**
 * Example: distributed circuit breaker backed by Redis.
 *
 * Prerequisite: redis-server running on 127.0.0.1:6379
 *
 * Run:
 *   php examples/redis_circuit_breaker.php
 */

if (!extension_loaded('redis')) {
    fwrite(STDERR, "ext-redis is not installed. Install it or use PredisClientAdapter instead.\n");
    exit(1);
}

$r = new \Redis();
$r->connect('127.0.0.1', 6379);

$adapter = new PhpRedisClientAdapter($r);
$cb = new RedisCircuitBreaker(
    redis: $adapter,
    name: 'brave_search',
    failureThreshold: 3,
    recoveryTimeoutSeconds: 5,
    halfOpenMaxCalls: 1,
);

// Simulate a failing provider
echo "Simulating 3 failures...\n";
for ($i = 1; $i <= 3; $i++) {
    $cb->recordFailure();
    echo "  failure #{$i}, state={$cb->getState()}\n";
}

echo "\nAttempting a call while OPEN...\n";
try {
    $cb->allowRequest();
    echo "  ALLOWED (unexpected!)\n";
} catch (\SearchGateway\Resilience\CircuitOpenException $e) {
    echo "  BLOCKED: {$e->getMessage()}\n";
}

echo "\nSleeping 6 seconds for recovery...\n";
sleep(6);

echo "First call after recovery window...\n";
$cb->allowRequest();
echo "  ALLOWED, state={$cb->getState()}\n";
$cb->recordSuccess();
echo "  Recorded success, state={$cb->getState()}\n";

echo "\nResetting breaker...\n";
$cb->reset();
echo "  state={$cb->getState()}\n";
