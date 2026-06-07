<?php

declare(strict_types=1);

namespace SearchGateway\Infrastructure;

final class InMemoryMetrics implements MetricsInterface
{
    /** @var array<string, int> */
    private array $counters = [];
    /** @var array<string, float> */
    private array $gauges = [];
    /** @var array<string, list<float>> */
    private array $timings = [];

    public function timing(string $name, float $seconds): void
    {
        $this->timings[$name][] = $seconds;
    }

    public function increment(string $name, int $count = 1): void
    {
        $this->counters[$name] = ($this->counters[$name] ?? 0) + $count;
    }

    public function gauge(string $name, float $value): void
    {
        $this->gauges[$name] = $value;
    }

    /**
     * @return array<string, int>
     */
    public function counters(): array
    {
        return $this->counters;
    }

    /**
     * @return array<string, float>
     */
    public function gauges(): array
    {
        return $this->gauges;
    }

    /**
     * @return array<string, array{count: int, sum: float, avg: float, min: float, max: float, p50: float, p95: float, p99: float}>
     */
    public function timingStats(): array
    {
        $out = [];
        foreach ($this->timings as $name => $values) {
            $count = count($values);
            $sum = array_sum($values);
            $sorted = $values;
            sort($sorted);
            $out[$name] = [
                'count' => $count,
                'sum' => $sum,
                'avg' => $count > 0 ? $sum / $count : 0.0,
                'min' => $count > 0 ? (float) min($values) : 0.0,
                'max' => $count > 0 ? (float) max($values) : 0.0,
                'p50' => $this->percentile($sorted, 0.50),
                'p95' => $this->percentile($sorted, 0.95),
                'p99' => $this->percentile($sorted, 0.99),
            ];
        }
        return $out;
    }

    /**
     * @param list<float> $sorted
     */
    private function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0.0;
        }
        $idx = (int) ceil($p * $n) - 1;
        if ($idx < 0) {
            $idx = 0;
        }
        if ($idx >= $n) {
            $idx = $n - 1;
        }
        return (float) $sorted[$idx];
    }
}
