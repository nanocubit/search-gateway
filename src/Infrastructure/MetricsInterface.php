<?php

declare(strict_types=1);

namespace SearchGateway\Infrastructure;

/**
 * Metrics sink compatible with StatsD, Prometheus push-gateway, or in-memory collectors.
 */
interface MetricsInterface
{
    public function timing(string $name, float $seconds): void;

    public function increment(string $name, int $count = 1): void;

    public function gauge(string $name, float $value): void;
}
