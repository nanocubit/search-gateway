<?php

declare(strict_types=1);

namespace SearchGateway\Analytics;

/**
 * Aggregates search metrics for dashboard / monitoring.
 * Time-series data: queries, latency, provider distribution, top queries.
 */
final class SearchAnalytics
{
    /** @var list<array<string, mixed>> */
    private array $events = [];
    private int $maxEvents = 10000;

    /**
     * @param array<string, mixed> $event
     */
    public function record(array $event): void
    {
        $event['ts'] = microtime(true);
        $this->events[] = $event;
        if (count($this->events) > $this->maxEvents) {
            array_shift($this->events);
        }
    }

    /**
     * Return all recorded events in insertion order.
     *
     * @return list<array<string, mixed>>
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * Top queries by frequency.
     *
     * @return array<string, int>
     */
    public function topQueries(int $limit = 10): array
    {
        $freq = [];
        foreach ($this->events as $e) {
            $qRaw = $e['query'] ?? '';
            $q = is_scalar($qRaw) ? (string) $qRaw : '';
            if ($q !== '') {
                $freq[$q] = ($freq[$q] ?? 0) + 1;
            }
        }
        arsort($freq);
        return array_slice($freq, 0, $limit, true);
    }

    /**
     * Average latency by provider.
     *
     * @return array<string, float>
     */
    public function latencyByProvider(): array
    {
        $sums = [];
        $counts = [];
        foreach ($this->events as $e) {
            $pRaw = $e['provider'] ?? 'unknown';
            $p = is_scalar($pRaw) ? (string) $pRaw : 'unknown';
            $latRaw = $e['latency_ms'] ?? 0;
            $lat = is_numeric($latRaw) ? (float) $latRaw : 0.0;
            $sums[$p] = ($sums[$p] ?? 0.0) + $lat;
            $counts[$p] = ($counts[$p] ?? 0) + 1;
        }
        $out = [];
        foreach (array_keys($sums) as $p) {
            $pStr = (string) $p;
            $sum = $sums[$pStr];
            $count = $counts[$pStr] ?? 1;
            $out[$pStr] = round($sum / max(1, $count), 2);
        }
        return $out;
    }

    /**
     * Error rate by provider.
     *
     * @return array<string, float>
     */
    public function errorRateByProvider(): array
    {
        $total = [];
        $errors = [];
        foreach ($this->events as $e) {
            $pRaw = $e['provider'] ?? 'unknown';
            $p = is_scalar($pRaw) ? (string) $pRaw : 'unknown';
            $total[$p] = ($total[$p] ?? 0) + 1;
            if (!empty($e['error'])) {
                $errors[$p] = ($errors[$p] ?? 0) + 1;
            }
        }
        $out = [];
        foreach (array_keys($total) as $p) {
            $pStr = (string) $p;
            $out[$pStr] = round(($errors[$pStr] ?? 0) / max(1, $total[$pStr]) * 100, 2);
        }
        return $out;
    }

    /**
     * Provider usage distribution.
     *
     * @return array<string, float>
     */
    public function providerDistribution(): array
    {
        $dist = [];
        foreach ($this->events as $e) {
            $pRaw = $e['provider'] ?? 'unknown';
            $p = is_scalar($pRaw) ? (string) $pRaw : 'unknown';
            $dist[$p] = ($dist[$p] ?? 0) + 1;
        }
        $total = array_sum($dist);
        $out = [];
        foreach ($dist as $p => $count) {
            $out[(string) $p] = round($count / max(1, $total) * 100, 2);
        }
        return $out;
    }
}
