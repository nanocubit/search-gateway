<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use SearchGateway\Analytics\SearchAnalytics;
use SearchGateway\Infrastructure\MetricsInterface;
use SearchGateway\Plugin\MetricsPlugin;
use SearchGateway\Plugin\PluginContext;
use SearchGateway\Request\SearchRequest;
use SearchGateway\Request\SearchResponse;

final class MetricsPluginTest extends TestCase
{
    public function testNameIsMetrics(): void
    {
        self::assertSame('metrics', (new MetricsPlugin())->name());
    }

    public function testAfterIncrementsCounterAndRecordsTiming(): void
    {
        $metrics = $this->createMock(MetricsInterface::class);
        $metrics->expects(self::once())
            ->method('increment')
            ->with('search_gateway_requests_total.v1.web.200');
        $metrics->expects(self::once())
            ->method('timing')
            ->with('search_gateway_latency_ms.v1.web.searchWeb', 0.0255);

        $plugin = new MetricsPlugin(metrics: $metrics);
        $resp = SearchResponse::ok('searchWeb', 'v1.web', [], ['latency_ms' => 25.5]);

        $plugin->afterSearch($resp, PluginContext::empty());
    }

    public function testNoMetricsNorAnalyticsIsNoOp(): void
    {
        $plugin = new MetricsPlugin();
        $resp = SearchResponse::ok('searchWeb', 'r', []);
        $out = $plugin->afterSearch($resp, PluginContext::empty());
        self::assertSame($resp, $out);
    }

    public function testRecordsEventToAnalytics(): void
    {
        $analytics = new SearchAnalytics();
        $plugin = new MetricsPlugin(analytics: $analytics);

        $resp = SearchResponse::ok('searchGen', 'v1.gen', [], ['latency_ms' => 10.0, 'apiKeyId' => 'k-1']);

        $plugin->afterSearch($resp, PluginContext::empty());
        self::assertNotEmpty($analytics->events());
    }

    public function testAnalyticsCarriesApiKeyIdFromMeta(): void
    {
        $analytics = new SearchAnalytics();
        $plugin = new MetricsPlugin(analytics: $analytics);
        $resp = SearchResponse::ok('searchWeb', 'r', [], ['apiKeyId' => 'k-42']);

        $plugin->afterSearch($resp, PluginContext::empty());
        $events = $analytics->events();
        self::assertSame('k-42', $events[0]['apiKeyId']);
    }

    public function testContextMetricsWinsOverConstructorMetrics(): void
    {
        $ctor = $this->createMock(MetricsInterface::class);
        $ctor->expects(self::never())->method('increment');
        $ctxMetrics = $this->createMock(MetricsInterface::class);
        $ctxMetrics->expects(self::once())->method('increment');

        $plugin = new MetricsPlugin(metrics: $ctor);
        $ctx = PluginContext::empty()->withMetrics($ctxMetrics);

        $plugin->afterSearch(SearchResponse::ok('searchWeb', 'r', []), $ctx);
    }

    public function testZeroLatencySkipsTimingButStillIncrements(): void
    {
        $metrics = $this->createMock(MetricsInterface::class);
        $metrics->expects(self::once())->method('increment');
        $metrics->expects(self::never())->method('timing');

        $plugin = new MetricsPlugin(metrics: $metrics);
        $plugin->afterSearch(SearchResponse::ok('searchWeb', 'r', []), PluginContext::empty()->withMetrics($metrics));
    }
}
