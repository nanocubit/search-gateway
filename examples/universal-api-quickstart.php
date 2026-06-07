<?php

declare(strict_types=1);

/**
 * Universal API quickstart — a runnable PSR-7/PSR-15 router.
 *
 * Start it with:
 *   php -S 127.0.0.1:8080 -t examples examples/universal-api-quickstart.php
 *
 * Then try:
 *   curl http://127.0.0.1:8080/metrics
 *   curl http://127.0.0.1:8080/docs/openapi.json
 *   curl http://127.0.0.1:8080/docs
 *   curl -H "Authorization: Bearer dev-admin-token" http://127.0.0.1:8080/admin/health
 *   curl -H "Authorization: Bearer dev-admin-token" http://127.0.0.1:8080/admin/analytics
 */

require __DIR__ . '/../vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use SearchGateway\Admin\AdminAuth;
use SearchGateway\Admin\AnalyticsAdminService;
use SearchGateway\Admin\HealthAdminService;
use SearchGateway\Admin\KeyAdminService;
use SearchGateway\Admin\RouteAdminService;
use SearchGateway\Analytics\SearchAnalytics;
use SearchGateway\ApiKey\ApiKeyHasher;
use SearchGateway\ApiKey\ApiKeyManager;
use SearchGateway\ApiKey\InMemoryApiKeyStore;
use SearchGateway\Controller\AdminController;
use SearchGateway\Controller\MetricsController;
use SearchGateway\Infrastructure\InMemoryMetrics;
use SearchGateway\Observability\InMemoryAuditLogger;
use SearchGateway\Observability\PrometheusExporter;
use SearchGateway\Portal\OpenApiGenerator;
use SearchGateway\Portal\PortalController;
use SearchGateway\Router\InMemoryRouteRegistry;
use SearchGateway\Router\RoutePresets;

$registry = new InMemoryRouteRegistry();
foreach (RoutePresets::all() as $route) {
    $registry->register($route);
}

$analytics = new SearchAnalytics();
$metrics = new InMemoryMetrics();
$audit = new InMemoryAuditLogger();
$apiKeyStore = new InMemoryApiKeyStore();
$apiKeyManager = new ApiKeyManager($apiKeyStore, new ApiKeyHasher());
$adminToken = getenv('SGW_ADMIN_TOKEN') ?: 'dev-admin-token';

$portal = new PortalController(new OpenApiGenerator($registry));
$metricsController = new MetricsController(new PrometheusExporter($metrics));
$admin = new AdminController(
    new AdminAuth($adminToken),
    new RouteAdminService($registry),
    new KeyAdminService($apiKeyManager),
    new HealthAdminService(null, $analytics),
    new AnalyticsAdminService($analytics),
);

$factory = new Psr17Factory();
$creator = new ServerRequestCreator($factory, $factory, $factory, $factory);

$server = $_SERVER;
$headers = [];
foreach ($server as $key => $value) {
    if (str_starts_with((string) $key, 'HTTP_')) {
        $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
        $headers[$name] = (string) $value;
    }
}
if (isset($server['CONTENT_TYPE'])) {
    $headers['content-type'] = (string) $server['CONTENT_TYPE'];
}
if (isset($server['CONTENT_LENGTH'])) {
    $headers['content-length'] = (string) $server['CONTENT_LENGTH'];
}

$request = $creator->fromArrays($server, $headers);
$path = $request->getUri()->getPath();

if (str_starts_with($path, '/docs') || str_starts_with($path, '/portal')) {
    $response = $portal->handle($request);
} elseif ($path === '/metrics') {
    $response = $metricsController->handle($request);
} elseif (str_starts_with($path, '/admin/')) {
    $response = $admin->handle($request);
} else {
    $response = $portal->handle($request);
}

http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header(sprintf('%s: %s', $name, $value));
    }
}
echo (string) $response->getBody();
