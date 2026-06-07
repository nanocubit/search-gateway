<?php

declare(strict_types=1);

namespace SearchGateway\Router;

use SearchGateway\Contract\SearchGatewayException;

interface RouteRegistryInterface
{
    /**
     * Register a route. Throws if a route with the same name already exists.
     *
     * @throws SearchGatewayException When the name is duplicate.
     */
    public function register(Route $route): void;

    /**
     * Find a route by its unique name. Returns null if not found.
     */
    public function get(string $name): ?Route;

    /**
     * Find all routes matching the given HTTP method and path.
     * Returns a list of [Route, pathParams] tuples. Empty list if nothing matches.
     *
     * @return list<array{Route, array<string, string>}>
     */
    public function match(string $method, string $path): array;

    /**
     * Return all registered routes in insertion order.
     *
     * @return list<Route>
     */
    public function all(): array;

    /**
     * Remove a route by name. Returns true if removed, false if not found.
     */
    public function remove(string $name): bool;

    /**
     * Remove all routes. Returns the number of routes removed.
     */
    public function clear(): int;

    /**
     * Total number of registered routes.
     */
    public function count(): int;
}
