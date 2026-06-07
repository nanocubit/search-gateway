<?php

declare(strict_types=1);

namespace SearchGateway\Observability;

use SearchGateway\Infrastructure\InMemoryMetrics;

final class PrometheusExporter
{
    public function __construct(private readonly ?InMemoryMetrics $metrics = null)
    {
    }

    public function export(): string
    {
        if ($this->metrics === null) {
            return $this->emptyResponse();
        }
        $lines = [];
        $this->appendCounters($lines);
        $this->appendGauges($lines);
        $this->appendTimings($lines);
        return $lines === [] ? $this->emptyResponse() : implode("\n", $lines) . "\n";
    }

    public function contentType(): string
    {
        return 'text/plain; version=0.0.4; charset=utf-8';
    }

    /**
     * @param list<string> $lines
     */
    private function appendCounters(array &$lines): void
    {
        if ($this->metrics === null) {
            return;
        }
        foreach ($this->metrics->counters() as $name => $value) {
            $lines[] = "# TYPE {$name} counter";
            $lines[] = "{$name} {$value}";
        }
    }

    /**
     * @param list<string> $lines
     */
    private function appendGauges(array &$lines): void
    {
        if ($this->metrics === null) {
            return;
        }
        foreach ($this->metrics->gauges() as $name => $value) {
            $lines[] = "# TYPE {$name} gauge";
            $lines[] = "{$name} {$value}";
        }
    }

    /**
     * @param list<string> $lines
     */
    private function appendTimings(array &$lines): void
    {
        if ($this->metrics === null) {
            return;
        }
        foreach ($this->metrics->timingStats() as $name => $stats) {
            $countName = $name . '_count';
            $sumName = $name . '_sum';
            $lines[] = "# TYPE {$name} summary";
            $lines[] = "{$countName} {$stats['count']}";
            $lines[] = "{$sumName} " . sprintf('%.6f', $stats['sum']);
        }
    }

    private function emptyResponse(): string
    {
        return "# No metrics collected yet\n";
    }
}
