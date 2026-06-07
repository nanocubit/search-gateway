<?php

declare(strict_types=1);

namespace SearchGateway\Router;

use SearchGateway\Contract\SearchGatewayException;

final class InMemoryRouteRegistry implements RouteRegistryInterface
{
    /** @var array<string, Route> */
    private array $routes = [];

    public function register(Route $route): void
    {
        if (isset($this->routes[$route->name])) {
            throw new SearchGatewayException(
                sprintf('Route with name "%s" is already registered', $route->name)
            );
        }
        $this->routes[$route->name] = $route;
    }

    public function get(string $name): ?Route
    {
        return $this->routes[$name] ?? null;
    }

    public function match(string $method, string $path): array
    {
        $matches = [];
        $normalised = PathMatcher::normalise($path);
        foreach ($this->routes as $route) {
            if (!$route->methodMatches($method)) {
                continue;
            }
            $params = PathMatcher::match($route->path, $normalised);
            if ($params !== null) {
                $matches[] = [$route->withPathParams($params), $params];
            }
        }
        return $matches;
    }

    public function all(): array
    {
        return array_values($this->routes);
    }

    public function remove(string $name): bool
    {
        if (!isset($this->routes[$name])) {
            return false;
        }
        unset($this->routes[$name]);
        return true;
    }

    public function clear(): int
    {
        $count = count($this->routes);
        $this->routes = [];
        return $count;
    }

    public function count(): int
    {
        return count($this->routes);
    }
}
