<?php

declare(strict_types=1);

namespace SearchGateway\Admin;

use SearchGateway\Router\Route;
use SearchGateway\Router\RouteRegistryInterface;

final class RouteAdminService
{
    public function __construct(private readonly RouteRegistryInterface $registry)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $rows = [];
        foreach ($this->registry->all() as $route) {
            $rows[] = $this->serialise($route);
        }
        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $name): ?array
    {
        $route = $this->registry->get($name);
        return $route === null ? null : $this->serialise($route);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function register(array $data): array
    {
        $name = $this->requireString($data, 'name');
        $method = $this->requireString($data, 'method');
        $path = $this->requireString($data, 'path');
        $action = $this->requireString($data, 'action');

        $route = new Route(
            name: $name,
            method: strtoupper($method),
            path: $path,
            action: $action,
            requiredScopes: $this->asStringList($data['scopes'] ?? []),
            rateLimit: $this->asRateLimit($data['rateLimit'] ?? null),
            config: $this->asAssoc($data['config'] ?? []),
        );
        $this->registry->register($route);
        return $this->serialise($route);
    }

    public function remove(string $name): bool
    {
        return $this->registry->remove($name);
    }

    public function count(): int
    {
        return $this->registry->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function serialise(Route $route): array
    {
        return [
            'name' => $route->name,
            'method' => $route->method,
            'path' => $route->path,
            'action' => $route->action,
            'scopes' => $route->requiredScopes,
            'rateLimit' => $route->rateLimit,
            'config' => $route->config,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException('Field "' . $key . '" is required and must be a non-empty string');
        }
        return $value;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function asStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * @param mixed $value
     * @return array{limit: int, window: int}|null
     */
    private function asRateLimit(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $limit = $value['limit'] ?? null;
        $window = $value['window'] ?? null;
        if (!is_int($limit) || !is_int($window)) {
            return null;
        }
        return ['limit' => $limit, 'window' => $window];
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function asAssoc(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (is_string($k)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
