<?php

declare(strict_types=1);

namespace SearchGateway\Tracing;

/**
 * LangSmith-style tracing collector.
 * Записывает каждый шаг цепочки: latency, tokens, cost, inputs, outputs.
 */
final class TraceCollector
{
    /** @var list<array<string, mixed>> */
    private array $spans = [];
    private ?string $currentTraceId = null;

    /**
     * @param array<string, mixed> $metadata
     */
    public function startTrace(string $name, array $metadata = []): string
    {
        $this->currentTraceId = bin2hex(random_bytes(8));
        $this->spans = [];
        $this->spans[] = [
            'trace_id' => $this->currentTraceId,
            'span_id' => 'root',
            'parent_id' => null,
            'name' => $name,
            'type' => 'trace',
            'start' => microtime(true),
            'end' => null,
            'latency_ms' => null,
            'metadata' => $metadata,
            'input' => null,
            'output' => null,
        ];
        return $this->currentTraceId;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function startSpan(string $name, array $input = []): string
    {
        $spanId = bin2hex(random_bytes(4));
        $this->spans[] = [
            'trace_id' => $this->currentTraceId,
            'span_id' => $spanId,
            'parent_id' => 'root',
            'name' => $name,
            'type' => 'span',
            'start' => microtime(true),
            'end' => null,
            'latency_ms' => null,
            'metadata' => [],
            'input' => $input,
            'output' => null,
        ];
        return $spanId;
    }

    /**
     * @param array<string, mixed> $output
     */
    public function endSpan(string $spanId, array $output = []): void
    {
        foreach ($this->spans as $idx => $_) {
            $span = $this->spans[$idx];
            if (($span['span_id'] ?? null) === $spanId) {
                $end = microtime(true);
                $start = is_numeric($span['start'] ?? null) ? (float) $span['start'] : $end;
                $this->spans[$idx]['end'] = $end;
                $this->spans[$idx]['latency_ms'] = round(($end - $start) * 1000, 2);
                $this->spans[$idx]['output'] = $output;
                break;
            }
        }
    }

    /**
     * @param array<string, mixed> $output
     */
    public function endTrace(array $output = []): void
    {
        foreach ($this->spans as $idx => $_) {
            $span = $this->spans[$idx];
            if (($span['span_id'] ?? null) === 'root') {
                $end = microtime(true);
                $start = is_numeric($span['start'] ?? null) ? (float) $span['start'] : $end;
                $this->spans[$idx]['end'] = $end;
                $this->spans[$idx]['latency_ms'] = round(($end - $start) * 1000, 2);
                $this->spans[$idx]['output'] = $output;
                break;
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSpans(): array
    {
        return $this->spans;
    }

    public function toJson(): string
    {
        $encoded = json_encode($this->spans, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return $encoded === false ? '[]' : $encoded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function exportToOpenTelemetry(): array
    {
        $out = [];
        foreach ($this->spans as $span) {
            $start = is_numeric($span['start'] ?? null) ? (float) $span['start'] : 0.0;
            $endRaw = $span['end'] ?? null;
            $end = is_numeric($endRaw) ? (float) $endRaw : $start;

            $inputJson = json_encode($span['input'] ?? []);
            $outputJson = json_encode($span['output'] ?? []);

            $out[] = [
                'traceId' => $span['trace_id'] ?? null,
                'spanId' => $span['span_id'] ?? null,
                'parentSpanId' => $span['parent_id'] ?? null,
                'name' => $span['name'] ?? '',
                'startTimeUnixNano' => (int) ($start * 1e9),
                'endTimeUnixNano' => (int) ($end * 1e9),
                'attributes' => [
                    'latency_ms' => $span['latency_ms'] ?? null,
                    'input' => $inputJson === false ? '' : $inputJson,
                    'output' => $outputJson === false ? '' : $outputJson,
                ],
            ];
        }
        return $out;
    }
}
