<?php

declare(strict_types=1);

namespace SearchGateway\Experiment;

use SearchGateway\Contract\SearchGatewayInterface;

/**
 * A/B testing framework for search configurations.
 * Сравнивает две конфигурации: control vs treatment.
 */
final class SearchExperiment
{
    /** @var array<string, array{control: SearchGatewayInterface, treatment: SearchGatewayInterface, results: list<array<string, mixed>>}> */
    private array $experiments = [];

    public function create(string $name, SearchGatewayInterface $control, SearchGatewayInterface $treatment): self
    {
        $this->experiments[$name] = [
            'control' => $control,
            'treatment' => $treatment,
            'results' => [],
        ];
        return $this;
    }

    /**
     * @param array<string, mixed> $options
     * @return array{variant: string, results: list<array{url:string, title:string, domain:string, passage:string, score:float}>}
     */
    public function run(string $name, string $query, array $options = [], string $userId = ''): array
    {
        $exp = $this->experiments[$name] ?? null;
        if ($exp === null) {
            throw new \RuntimeException("Experiment '{$name}' not found");
        }

        $isTreatment = (crc32($name . ':' . $userId) % 2) === 1;
        $gateway = $isTreatment ? $exp['treatment'] : $exp['control'];
        $variant = $isTreatment ? 'treatment' : 'control';

        $start = microtime(true);
        try {
            $result = $gateway->llmContext($query, $options);
            $latency = round((microtime(true) - $start) * 1000, 2);
            $this->experiments[$name]['results'][] = [
                'variant' => $variant,
                'query' => $query,
                'result_count' => count($result),
                'latency_ms' => $latency,
                'error' => null,
            ];
            return ['variant' => $variant, 'results' => $result];
        } catch (\Throwable $e) {
            $latency = round((microtime(true) - $start) * 1000, 2);
            $this->experiments[$name]['results'][] = [
                'variant' => $variant,
                'query' => $query,
                'result_count' => 0,
                'latency_ms' => $latency,
                'error' => $e->getMessage(),
            ];
            throw $e;
        }
    }

    /**
     * @return array<string, int|float>
     */
    public function getStats(string $name): array
    {
        $results = $this->experiments[$name]['results'] ?? [];
        $control = array_filter($results, static fn(array $r): bool => $r['variant'] === 'control');
        $treatment = array_filter($results, static fn(array $r): bool => $r['variant'] === 'treatment');

        return [
            'control_count' => count($control),
            'treatment_count' => count($treatment),
            'control_avg_latency' => $this->avg($control, 'latency_ms'),
            'treatment_avg_latency' => $this->avg($treatment, 'latency_ms'),
            'control_avg_results' => $this->avg($control, 'result_count'),
            'treatment_avg_results' => $this->avg($treatment, 'result_count'),
            'control_error_rate' => $this->errorRate($control),
            'treatment_error_rate' => $this->errorRate($treatment),
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function avg(array $items, string $key): float
    {
        $vals = array_column($items, $key);
        if ($vals === []) {
            return 0.0;
        }
        $sum = 0.0;
        $count = 0;
        foreach ($vals as $v) {
            if (is_numeric($v)) {
                $sum += (float) $v;
                $count++;
            }
        }
        return $count === 0 ? 0.0 : round($sum / $count, 2);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function errorRate(array $items): float
    {
        $total = count($items);
        if ($total === 0) {
            return 0.0;
        }
        $errors = 0;
        foreach ($items as $r) {
            if (($r['error'] ?? null) !== null) {
                $errors++;
            }
        }
        return round($errors / $total * 100, 2);
    }
}
