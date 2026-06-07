<?php

declare(strict_types=1);

namespace SearchGateway\Plugin;

use SearchGateway\Analytics\SearchAnalytics;
use SearchGateway\Formatter\ResponseFormatter;
use SearchGateway\Infrastructure\CacheInterface;
use SearchGateway\Infrastructure\LoggerInterface;
use SearchGateway\Infrastructure\MetricsInterface;

final class PluginContext
{
    public function __construct(
        public readonly ?LoggerInterface $logger = null,
        public readonly ?MetricsInterface $metrics = null,
        public readonly ?CacheInterface $cache = null,
        public readonly ?SearchAnalytics $analytics = null,
        public readonly ?ResponseFormatter $formatter = null,
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    public function withLogger(LoggerInterface $logger): self
    {
        return new self($logger, $this->metrics, $this->cache, $this->analytics, $this->formatter);
    }

    public function withMetrics(MetricsInterface $metrics): self
    {
        return new self($this->logger, $metrics, $this->cache, $this->analytics, $this->formatter);
    }

    public function withCache(CacheInterface $cache): self
    {
        return new self($this->logger, $this->metrics, $cache, $this->analytics, $this->formatter);
    }

    public function withAnalytics(SearchAnalytics $analytics): self
    {
        return new self($this->logger, $this->metrics, $this->cache, $analytics, $this->formatter);
    }

    public function withFormatter(ResponseFormatter $formatter): self
    {
        return new self($this->logger, $this->metrics, $this->cache, $this->analytics, $formatter);
    }
}
