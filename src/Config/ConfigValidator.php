<?php

declare(strict_types=1);

namespace SearchGateway\Config;

use SearchGateway\Contract\SearchGatewayException;

/**
 * Strict validation of gateway configuration.
 */
final class ConfigValidator
{
    /**
     * @param array<string, mixed> $config
     */
    public function validate(array $config): void
    {
        $errors = [];

        $providers = $config['providers'] ?? null;
        if (!is_array($providers) || $providers === []) {
            $errors[] = 'At least one provider must be configured';
            return;
        }
        foreach ($providers as $name => $provider) {
            $nameStr = (string) $name;
            if (!is_array($provider)) {
                $errors[] = "Provider '{$nameStr}' must be a configuration array";
                continue;
            }
            $type = $provider['type'] ?? null;
            $apiKey = $provider['api_key'] ?? null;
            if (!is_string($type) || $type === '') {
                $errors[] = "Provider '{$nameStr}' missing 'type'";
            }
            if ($type === 'brave' && !is_string($apiKey)) {
                $errors[] = "Brave provider '{$nameStr}' missing 'api_key'";
            }
            if ($type === 'perplexity' && !is_string($apiKey)) {
                $errors[] = "Perplexity provider '{$nameStr}' missing 'api_key'";
            }
        }

        $cache = $config['cache'] ?? null;
        if (is_array($cache) && empty($cache['driver'])) {
            $errors[] = 'Cache configuration missing driver';
        }

        $rateLimit = $config['rate_limit'] ?? null;
        if (is_array($rateLimit)) {
            foreach ($rateLimit as $provider => $limit) {
                $providerStr = (string) $provider;
                if (!is_array($limit)) {
                    $errors[] = "Rate limit for '{$providerStr}' must be a configuration array";
                    continue;
                }
                if (empty($limit['max']) || empty($limit['window'])) {
                    $errors[] = "Rate limit for '{$providerStr}' must have 'max' and 'window'";
                }
            }
        }

        if ($errors !== []) {
            throw new SearchGatewayException('Config validation failed: ' . implode('; ', $errors));
        }
    }
}
