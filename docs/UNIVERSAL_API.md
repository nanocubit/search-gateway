# Search Gateway — Universal API

The Universal API is a complete rewrite of the control plane on top of PSR-7/PSR-15. It turns the library into a fully programmable HTTP gateway with routing, auth, streaming, observability, and admin endpoints.

## Quick start

```php
use SearchGateway\Builder\GatewayBuilder;
use SearchGateway\Router\InMemoryRouteRegistry;
use SearchGateway\Router\Route;
use SearchGateway\Router\RoutePresets;
use SearchGateway\Http\Middleware\{AuthMiddleware, CorsMiddleware, JsonBodyMiddleware, RateLimitMiddleware, AuditMiddleware};
use SearchGateway\Controller\{SearchGatewayController, AdminController, StreamController, MetricsController};
use SearchGateway\Plugin\{PluginPipeline, LoggingPlugin, MetricsPlugin};
use SearchGateway\Portal\{OpenApiGenerator, PortalController};
use SearchGateway\Observability\{PrometheusExporter, InMemoryMetrics, InMemoryAuditLogger};

$builder = new GatewayBuilder();
$registry = new InMemoryRouteRegistry();
foreach (RoutePresets::all() as $route) {
    $registry->register($route);
}

$pipeline = (new PluginPipeline())
    ->withPlugin(new LoggingPlugin())
    ->withPlugin(new MetricsPlugin(new InMemoryMetrics()));

$controller = new SearchGatewayController(
    registry: $registry,
    resolver: new \SearchGateway\Router\RouteResolver(),
    pipeline: $pipeline,
    analytics: new \SearchGateway\Analytics\SearchAnalytics(),
    formatter: $builder->buildFormatter(),
    guardrails: $builder->buildGuardrails(),
);

$portalController = new PortalController(new OpenApiGenerator($registry));
$metricsController = new MetricsController(new PrometheusExporter(new InMemoryMetrics()));
```

## Architecture

```
HTTP request
  ↓
[ Auth → CORS → JsonBody → RateLimit → Audit ]   ← PSR-15 middleware chain
  ↓
[ RouteRegistry::match() ]                       ← :param + * wildcard
  ↓
[ RouteResolver ]                                ← PSR-7 → SearchRequest DTO
  ↓
[ Plugin::beforeSearch ]                         ← logging, metrics, cache key
  ↓
[ SearchGatewayController::dispatch ]            ← 7 gateway actions
  ↓
[ SearchGuardrails::validate ]                   ← 422 on violation
  ↓
[ Plugin::afterSearch ]                          ← reversed order
  ↓
[ RealFormatter::toMarkdown ]                    ← human-readable output
  ↓
[ JsonResponse | SseEmitter::emit ]              ← JSON or SSE stream
```

## Routes

Routes are registered with `Route(name, method, path, action, ...)`. Built-in actions:

| Constant | Path (preset) | Action |
|---|---|---|
| `Route::ACTION_SEARCH_WEB` | `POST /v1/search/web` | Web search |
| `Route::ACTION_SEARCH_NEWS` | `POST /v1/search/news` | News search |
| `Route::ACTION_SEARCH_IMAGES` | `POST /v1/search/images` | Image search |
| `Route::ACTION_SEARCH_GEN` | `POST /v1/search/gen` | Generative RAG |
| `Route::ACTION_LLM_CONTEXT` | `POST /v1/llm/context` | LLM context only |
| `Route::ACTION_HYBRID` | `POST /v1/hybrid` | Search + LLM hybrid |
| `Route::ACTION_WORDSTAT` | `POST /v1/wordstat` | Wordstat analytics |
| `Route::ACTION_SEARCH_WEB` | `GET /v1/browser/history` | Browser history (via `browserHistory()` preset) |
| `stream` | `POST /v1/stream/*` | SSE streaming |

Use `RoutePresets::all()` to register the full default set or pick a single group: `webSearch()`, `generative()`, `analytics()`, `streaming()`, `browserHistory()`.

## Configuration

Routes can be loaded from a file at runtime:

```php
$loader = new RouteConfigLoader();
$routes = $loader->loadFromFile('/etc/sgw/routes.yaml');
foreach ($routes as $route) {
    $registry->register($route);
}
```

`routes.json`:
```json
{
  "routes": [
    {"name": "v1.search.web", "method": "POST", "path": "/v1/search/web", "action": "searchWeb", "requiredScopes": ["search:web"], "rateLimit": {"limit": 100, "window": 60}}
  ]
}
```

`routes.yaml`:
```yaml
routes:
  - name: v1.search.web
    method: POST
    path: /v1/search/web
    action: searchWeb
    requiredScopes: [search:web]
    rateLimit: {limit: 100, window: 60}
```

YAML requires `symfony/yaml` (installed on demand via composer suggest) or your own `YamlParserInterface` implementation.

`routes.php`:
```php
<?php
return [
    'routes' => [
        [
            'name' => 'v1.search.web',
            'method' => 'POST',
            'path' => '/v1/search/web',
            'action' => 'searchWeb',
            'requiredScopes' => ['search:web'],
        ],
    ],
];
```

## Authentication

API keys are managed by `ApiKeyManager` with a `Bearer` token scheme:

```php
$store = new InMemoryApiKeyStore();
$manager = new ApiKeyManager($store, new ApiKeyHasher());

$rawKey = $manager->create('My App', ['search:web', 'search:news']);  // returns "sgw_..."

$apiKey = $manager->authenticate($rawKey, 'search:web');  // throws on 401/403
```

`AuthMiddleware` enforces the scope on each request and attaches `sgw.apiKey` and `sgw.apiKeyId` attributes to the PSR-7 request.

The admin plane uses a separate `SGW_ADMIN_TOKEN` env with role-based access:

| Role | Endpoint |
|---|---|
| `admin:super` | everything |
| `admin:routes` | `/admin/routes*` |
| `admin:keys` | `/admin/keys*` |
| `admin:read` | `/admin/health`, `/admin/analytics` |

## Streaming

SSE is handled by `StreamController`. A streaming route supplies a generator either as `iterable` (static) or as a `callable(SearchRequest): iterable`:

```php
use SearchGateway\Controller\StreamController;
use SearchGateway\Streaming\SseEmitter;

$registry->register(new Route(
    name: 'v1.stream.chat',
    method: Route::METHOD_POST,
    path: '/v1/stream/chat',
    action: 'stream',
    requiredScopes: ['llm:stream'],
    config: [
        'stream_generator' => fn(\SearchGateway\Request\SearchRequest $r): iterable
            => yield from $llmClient->chatStream($r->query),
    ],
));
```

The response uses the standard SSE format:

```
event: chunk
data: {"index":0,"text":"Hello "}

event: chunk
data: {"index":1,"text":"world"}

event: done
data: {"ok":true}
```

## Plugins

`PluginInterface` lets you transform the request before dispatch and the response after:

```php
final class CacheReadPlugin implements PluginInterface
{
    public function __construct(private CacheInterface $cache) {}
    public function name(): string { return 'cache.read'; }
    public function beforeSearch(SearchRequest $request, PluginContext $ctx): SearchRequest
    {
        $key = sha256(json_encode([$request->query, $request->providers]));
        $hit = $this->cache->get($key);
        if ($hit !== null) {
            // mark request to short-circuit dispatch
        }
        return $request;
    }
    public function afterSearch(SearchResponse $response, PluginContext $ctx): SearchResponse
    {
        return $response;
    }
}
```

Built-in plugins: `LoggingPlugin`, `MetricsPlugin`, `CacheKeyPlugin`. Plugins are added to a `PluginPipeline` and run in declared order on `before`, then reversed on `after`.

## Observability

Two complementary sinks:

1. **Metrics** — `InMemoryMetrics` (counter/gauge/timing) exposed via `PrometheusExporter` at `GET /metrics`:

```
# TYPE requests_total counter
requests_total 42
# TYPE http_request_ms summary
http_request_ms_count 17
http_request_ms_sum 4.231000
```

2. **Audit** — `AuditLoggerInterface` with `InMemoryAuditLogger` and `FileAuditLogger` (atomic JSON-lines with `flock`). Records every admin action and HTTP event:

```php
$audit->log('route.register', 'admin-token', ['name' => 'v1.stream']);
$events = $audit->events(50);  // tail of 50 most recent
```

3. **SearchAnalytics** — per-request events (query, provider, latency, success, status, route). Powers the `AnalyticsAdminService` summary: top queries, provider distribution, latency, error rate.

## OpenAPI & Portal

`OpenApiGenerator` produces an OpenAPI 3.0.3 spec from the registry, served at `/docs/openapi.json`. `PortalController` provides:

| Path | Content |
|---|---|
| `GET /docs/openapi.json` | Machine-readable spec |
| `GET /docs` | Swagger UI (CDN: swagger-ui-dist@5.17.14) |
| `GET /docs/sandbox` | Browser-based route tester |
| `GET /docs/portal` | Developer landing page |

The spec includes:
- `components.securitySchemes.bearerAuth` (HTTP Bearer)
- `components.schemas` for `SearchRequest`, `SearchResponse`, `Error`
- Per-route `operationId`, `tags`, `requestBody` (POST/PUT/PATCH), `responses` (200/400/401/403/429/500)
- Scope and rate-limit annotations in the description

## Admin

`AdminController` exposes 10 endpoints under `/admin/*`:

| Method | Path | Auth |
|---|---|---|
| `GET` | `/admin/health` | `admin:read` |
| `GET` | `/admin/analytics` | `admin:read` |
| `GET` | `/admin/routes` | `admin:routes` |
| `POST` | `/admin/routes` | `admin:routes` |
| `GET` | `/admin/routes/{name}` | `admin:routes` |
| `DELETE` | `/admin/routes/{name}` | `admin:routes` |
| `GET` | `/admin/keys` | `admin:keys` |
| `POST` | `/admin/keys` | `admin:keys` |
| `GET` | `/admin/keys/{id}` | `admin:keys` |
| `DELETE` | `/admin/keys/{id}` | `admin:keys` |

All responses are JSON. Keys created via `POST /admin/keys` return the raw key in `rawKey` exactly once.

## Middleware

PSR-15 middleware shipped with the gateway:

- `AuthMiddleware` — Bearer + scopes
- `CorsMiddleware` — preflight + CORS headers
- `JsonBodyMiddleware` — parse JSON, attach `sgw.parsedBody`
- `RateLimitMiddleware` — token-bucket per key/IP, `X-RateLimit-*` headers
- `AuditMiddleware` — `X-Response-Time-ms` + events in `SearchAnalytics`

Chain them in your preferred order:

```php
$auth = new AuthMiddleware($apiKeyManager);
$auth->setNext(new CorsMiddleware())
    ->setNext(new JsonBodyMiddleware())
    ->setNext(new RateLimitMiddleware(['limit' => 100, 'window' => 60]))
    ->setNext(new AuditMiddleware($analytics));

$response = $auth->handle($request);
```

## Persistence

Two stores are provided for both API keys and audit logs:

- `InMemoryApiKeyStore` / `InMemoryAuditLogger` — fast, process-local
- `FileApiKeyStore` / `FileAuditLogger` — atomic JSON with `flock` + `tmp+rename` + `chmod 0o600`

Both implement the same interface and can be swapped at runtime:

```php
$keyStore = new FileApiKeyStore('/var/lib/sgw/keys.json');
$audit = new FileAuditLogger('/var/log/sgw/audit.log');
```

## Standards compliance

- **PSR-4** — `SearchGateway\` namespace
- **PSR-7** — `psr/http-message` (required), `nyholm/psr7` (dev)
- **PSR-15** — `psr/http-server-handler` (required), `psr/http-server-middleware` (dev)
- **PSR-12** — code style (lineLimit=140)
- **PHPStan level 9** — strict static analysis
- **PHPUnit 11** — 379 tests, 958 assertions

## Browser History Gateway

`HybridBrowserHistoryGateway` connects the Universal API to the [AI Browser Tracker 3.1](ai-browser-tracker/) — a local Flask server that queries browser history via DuckDB + Zvec + NeuG.

```php
use SearchGateway\Builder\GatewayBuilder;
use SearchGateway\Gateway\HybridBrowserHistoryGateway;

$builder = new GatewayBuilder();
$builder->addHybridBrowserHistory(
    baseUrl: 'http://127.0.0.1:5000',
    authToken: 'ai-agent-hybrid-token-2026',
    timeout: 5,
);
```

The route is registered via `RoutePresets::browserHistory()`:

| Method | Path | Action | Scope |
|--------|------|--------|-------|
| GET | `/v1/browser/history` | `searchWeb` | `browser:history` |

The gateway forwards `searchWeb()` calls to the AI Browser Tracker Flask server (`/search/similar`, `/search/hybrid`, `/api/stats`). News, images, and wordstat return empty arrays. An unreachable server throws `SearchGatewayException`.

Configuration for Laravel:

```php
// config/search-gateway.php
'hybrid_browser_history' => [
    'enabled'    => env('HYBRID_BROWSER_HISTORY_ENABLED', false),
    'base_url'   => env('HYBRID_BROWSER_HISTORY_URL', 'http://127.0.0.1:5000'),
    'auth_token' => env('HYBRID_BROWSER_HISTORY_TOKEN', 'ai-agent-hybrid-token-2026'),
    'timeout'    => (int) env('HYBRID_BROWSER_HISTORY_TIMEOUT', 5),
],
```
