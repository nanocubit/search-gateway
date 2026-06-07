<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Observability;

use PHPUnit\Framework\TestCase;
use SearchGateway\Infrastructure\InMemoryMetrics;
use SearchGateway\Observability\PrometheusExporter;

final class PrometheusExporterTest extends TestCase
{
    public function testEmptyMetricsProducesComment(): void
    {
        $exporter = new PrometheusExporter(new InMemoryMetrics());
        $output = $exporter->export();

        self::assertStringContainsString('# No metrics collected yet', $output);
    }

    public function testNullMetricsProducesComment(): void
    {
        $exporter = new PrometheusExporter(null);
        $output = $exporter->export();

        self::assertStringContainsString('# No metrics collected yet', $output);
    }

    public function testCounterIsExportedAsPrometheusType(): void
    {
        $metrics = new InMemoryMetrics();
        $metrics->increment('requests_total');
        $metrics->increment('requests_total', 4);

        $exporter = new PrometheusExporter($metrics);
        $output = $exporter->export();

        self::assertStringContainsString('# TYPE requests_total counter', $output);
        self::assertStringContainsString('requests_total 5', $output);
    }

    public function testGaugeIsExportedAsPrometheusType(): void
    {
        $metrics = new InMemoryMetrics();
        $metrics->gauge('queue_depth', 42.5);

        $exporter = new PrometheusExporter($metrics);
        $output = $exporter->export();

        self::assertStringContainsString('# TYPE queue_depth gauge', $output);
        self::assertStringContainsString('queue_depth 42.5', $output);
    }

    public function testTimingIsExportedAsSummaryWithCountAndSum(): void
    {
        $metrics = new InMemoryMetrics();
        $metrics->timing('http_request_ms', 0.1);
        $metrics->timing('http_request_ms', 0.2);
        $metrics->timing('http_request_ms', 0.3);

        $exporter = new PrometheusExporter($metrics);
        $output = $exporter->export();

        self::assertStringContainsString('# TYPE http_request_ms summary', $output);
        self::assertStringContainsString('http_request_ms_count 3', $output);
        self::assertStringContainsString('http_request_ms_sum 0.600000', $output);
    }

    public function testContentTypeIsPrometheusStandard(): void
    {
        $exporter = new PrometheusExporter();
        self::assertSame('text/plain; version=0.0.4; charset=utf-8', $exporter->contentType());
    }

    public function testMixedMetricsAreAllExported(): void
    {
        $metrics = new InMemoryMetrics();
        $metrics->increment('hits', 10);
        $metrics->gauge('active', 3.0);
        $metrics->timing('latency', 0.05);

        $exporter = new PrometheusExporter($metrics);
        $output = $exporter->export();

        self::assertStringContainsString('hits 10', $output);
        self::assertStringContainsString('active 3', $output);
        self::assertStringContainsString('latency_count 1', $output);
        self::assertStringContainsString('latency_sum 0.050000', $output);
    }
}
