<?php

declare(strict_types=1);

namespace SearchGateway\Cost;

/**
 * Трекер стоимости API-вызовов.
 * Perplexity/Brave/Yandex имеют разные pricing tiers.
 */
final class CostTracker
{
    /** @var array<string, array{input: float, output: float, search: float}> $rates Per-1K tokens or per-call */
    private array $rates = [];

    /** @var list<array{provider: string, model: string, tokens_in: int, tokens_out: int, calls: int, cost_usd: float, timestamp: int}> */
    private array $log = [];

    public function setRate(string $provider, string $model, float $inputPer1K, float $outputPer1K, float $searchPerCall = 0): void
    {
        $this->rates["{$provider}:{$model}"] = [
            'input' => $inputPer1K,
            'output' => $outputPer1K,
            'search' => $searchPerCall,
        ];
    }

    public function record(string $provider, string $model, int $tokensIn, int $tokensOut, int $searchCalls = 0): void
    {
        $rate = $this->rates["{$provider}:{$model}"] ?? ['input' => 0, 'output' => 0, 'search' => 0];
        $cost = ($tokensIn / 1000) * $rate['input']
              + ($tokensOut / 1000) * $rate['output']
              + $searchCalls * $rate['search'];

        $this->log[] = [
            'provider' => $provider,
            'model' => $model,
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'calls' => $searchCalls,
            'cost_usd' => round($cost, 6),
            'timestamp' => time(),
        ];
    }

    public function totalCost(): float
    {
        return array_sum(array_column($this->log, 'cost_usd'));
    }

    /**
     * @return array<string, float>
     */
    public function costByProvider(): array
    {
        $map = [];
        foreach ($this->log as $entry) {
            $map[$entry['provider']] = ($map[$entry['provider']] ?? 0) + $entry['cost_usd'];
        }
        return $map;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getLog(): array
    {
        return $this->log;
    }
}
