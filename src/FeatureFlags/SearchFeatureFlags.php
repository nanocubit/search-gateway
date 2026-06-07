<?php

declare(strict_types=1);

namespace SearchGateway\FeatureFlags;

/**
 * Feature flags для search: включение/выключение провайдеров, стратегий, экспериментов.
 */
final class SearchFeatureFlags
{
    /** @var array<string, bool> */
    private array $flags = [];

    public function set(string $flag, bool $value): self
    {
        $this->flags[$flag] = $value;
        return $this;
    }

    public function isEnabled(string $flag, bool $default = false): bool
    {
        return $this->flags[$flag] ?? $default;
    }

    public function providerEnabled(string $provider): bool
    {
        return $this->isEnabled("provider.{$provider}", true);
    }

    public function strategyEnabled(string $strategy): bool
    {
        return $this->isEnabled("strategy.{$strategy}", true);
    }

    public function experimentEnabled(string $experiment, string $userId): bool
    {
        // Deterministic rollout based on user hash
        $hash = crc32($experiment . ':' . $userId);
        $percentage = (int) ($this->flags["experiment.{$experiment}.percentage"] ?? 0);
        return ($hash % 100) < $percentage;
    }
}
