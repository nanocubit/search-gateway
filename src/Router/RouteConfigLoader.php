<?php

declare(strict_types=1);

namespace SearchGateway\Router;

use RuntimeException;
use SearchGateway\Contract\SearchGatewayException;

final class RouteConfigLoader
{
    public function __construct(private readonly ?YamlParserInterface $yamlParser = null)
    {
    }

    /**
     * @return list<Route>
     */
    public function loadFromFile(string $path): array
    {
        if (!file_exists($path)) {
            throw new SearchGatewayException('Route config not found: ' . $path, 404);
        }
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'yaml', 'yml' => $this->loadYaml($path),
            'json' => $this->loadJson($path),
            'php' => $this->loadPhp($path),
            default => throw new SearchGatewayException('Unsupported route config extension: ' . $ext, 400),
        };
    }

    /**
     * @param list<array<string, mixed>> $configs
     * @return list<Route>
     */
    public function loadFromArray(array $configs): array
    {
        $routes = [];
        foreach ($configs as $cfg) {
            $routes[] = $this->buildRoute($cfg);
        }
        return $routes;
    }

    /**
     * @return list<Route>
     */
    private function loadYaml(string $path): array
    {
        $raw = (string) file_get_contents($path);
        if ($raw === '') {
            return [];
        }
        if ($this->yamlParser !== null) {
            $parsed = $this->yamlParser->parse($raw);
            $data = is_array($parsed) ? $parsed : [];
        } elseif (class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            $parsed = \Symfony\Component\Yaml\Yaml::parse($raw);
            $data = is_array($parsed) ? $parsed : [];
        } else {
            throw new SearchGatewayException(
                'YAML parsing requires symfony/yaml (composer require symfony/yaml)',
                500,
            );
        }
        $list = $this->extractRouteList($data);
        return $this->loadFromArray($list);
    }

    /**
     * @return list<Route>
     */
    private function loadJson(string $path): array
    {
        $raw = (string) file_get_contents($path);
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new SearchGatewayException('Invalid JSON in route config: ' . $path, 400);
        }
        $list = $this->extractRouteList($data);
        return $this->loadFromArray($list);
    }

    /**
     * @return list<Route>
     */
    private function loadPhp(string $path): array
    {
        $data = require $path;
        if (!is_array($data)) {
            throw new SearchGatewayException('PHP route config must return array: ' . $path, 400);
        }
        $list = $this->extractRouteList($data);
        return $this->loadFromArray($list);
    }

    /**
     * @param array<mixed, mixed> $data
     * @return list<array<string, mixed>>
     */
    private function extractRouteList(array $data): array
    {
        if (isset($data['routes']) && is_array($data['routes'])) {
            $data = $data['routes'];
        }
        $list = [];
        foreach ($data as $entry) {
            if (is_array($entry)) {
                $list[] = $entry;
            }
        }
        return $list;
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function buildRoute(array $cfg): Route
    {
        $name = $this->str($cfg, 'name', required: true);
        $method = strtoupper($this->str($cfg, 'method', required: true));
        $path = $this->str($cfg, 'path', required: true);
        $action = $this->str($cfg, 'action', required: true);
        $requiredScopes = $this->listOfStrings(is_array($cfg['requiredScopes'] ?? null) ? $cfg['requiredScopes'] : []);
        $rateLimitRaw = $cfg['rateLimit'] ?? null;
        $rateLimit = is_array($rateLimitRaw) ? [
            'limit' => isset($rateLimitRaw['limit']) ? (int) $rateLimitRaw['limit'] : 0,
            'window' => isset($rateLimitRaw['window']) ? (int) $rateLimitRaw['window'] : 0,
        ] : null;
        $configRaw = $cfg['config'] ?? [];
        $config = is_array($configRaw) ? $configRaw : [];

        return new Route(
            name: $name,
            method: $method,
            path: $path,
            action: $action,
            requiredScopes: $requiredScopes,
            rateLimit: $rateLimit,
            config: $config,
        );
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function str(array $cfg, string $key, bool $required): string
    {
        $value = $cfg[$key] ?? null;
        if ($value === null || !is_scalar($value)) {
            if ($required) {
                throw new SearchGatewayException(
                    sprintf('Route config missing "%s" field', $key),
                    400,
                );
            }
            return '';
        }
        return (string) $value;
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private function listOfStrings(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            if (is_scalar($v)) {
                $out[] = (string) $v;
            }
        }
        return $out;
    }
}
