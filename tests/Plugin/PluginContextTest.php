<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use SearchGateway\Plugin\PluginContext;

final class PluginContextTest extends TestCase
{
    public function testEmptyContextReturnsNulls(): void
    {
        $ctx = PluginContext::empty();
        self::assertNull($ctx->logger);
        self::assertNull($ctx->metrics);
        self::assertNull($ctx->cache);
        self::assertNull($ctx->analytics);
        self::assertNull($ctx->formatter);
    }

    public function testWithMethodsReturnCopiesWithFieldReplaced(): void
    {
        $logger = $this->createMock(\SearchGateway\Infrastructure\LoggerInterface::class);
        $metrics = $this->createMock(\SearchGateway\Infrastructure\MetricsInterface::class);
        $cache = $this->createMock(\SearchGateway\Infrastructure\CacheInterface::class);
        $analytics = new \SearchGateway\Analytics\SearchAnalytics();
        $formatter = new \SearchGateway\Formatter\ResponseFormatter();

        $base = PluginContext::empty();
        $withLogger = $base->withLogger($logger);
        $withMetrics = $base->withMetrics($metrics);
        $withCache = $base->withCache($cache);
        $withAnalytics = $base->withAnalytics($analytics);
        $withFormatter = $base->withFormatter($formatter);

        self::assertNotSame($base, $withLogger);
        self::assertSame($logger, $withLogger->logger);
        self::assertSame($metrics, $withMetrics->metrics);
        self::assertSame($cache, $withCache->cache);
        self::assertSame($analytics, $withAnalytics->analytics);
        self::assertSame($formatter, $withFormatter->formatter);

        self::assertNull($base->logger);
        self::assertNull($withLogger->metrics);
    }

    public function testChainedWithMethodsPreserveEarlierReplacements(): void
    {
        $logger = $this->createMock(\SearchGateway\Infrastructure\LoggerInterface::class);
        $metrics = $this->createMock(\SearchGateway\Infrastructure\MetricsInterface::class);

        $ctx = PluginContext::empty()->withLogger($logger)->withMetrics($metrics);

        self::assertSame($logger, $ctx->logger);
        self::assertSame($metrics, $ctx->metrics);
    }
}
