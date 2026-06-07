<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SearchGateway\ApiKey\ApiKeyHasher;
use SearchGateway\ApiKey\ApiKeyManager;
use SearchGateway\ApiKey\InMemoryApiKeyStore;
use SearchGateway\Builder\GatewayBuilder;
use SearchGateway\Router\InMemoryRouteRegistry;
use SearchGateway\Router\RouteConfigLoader;
use SearchGateway\Router\RoutePresets;

$store = new InMemoryApiKeyStore();
$manager = new ApiKeyManager($store, new ApiKeyHasher());
[$rawKey, $key] = $manager->create('Demo', ['search:web']);
echo "API key (use as Bearer token): {$rawKey}\n";
echo "Key id: {$key->id()}, scopes: " . implode(', ', $key->scopes()) . "\n\n";

$registry = new InMemoryRouteRegistry();
foreach (RoutePresets::all() as $route) {
    $registry->register($route);
}
echo "Registered " . count($registry->all()) . " routes from presets\n\n";

$loader = new RouteConfigLoader();
$jsonRoutes = $loader->loadFromArray(json_decode((string) file_get_contents(__DIR__ . '/routes.json'), true)['routes'] ?? []);
echo "Loaded " . count($jsonRoutes) . " routes from examples/routes.json\n\n";

$builder = new GatewayBuilder();
$gateway = $builder->build();
echo "Built gateway: " . $gateway::class . "\n";
