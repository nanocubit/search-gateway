# Changelog

All notable changes to **Search Gateway** are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased] — Universal API

### Added

**Universal API control plane** (PSR-7/PSR-15) — a complete HTTP gateway on top of the existing library:

- **Router layer** — `Route`, `RouteRegistryInterface`, `InMemoryRouteRegistry`, `PathMatcher` (`:param` + `*` wildcard), `RouteResolver` (PSR-7 → `SearchRequest` DTO), `RouteConfigLoader` (JSON / YAML / PHP), `RoutePresets` (5 ready-made groups), `YamlParserInterface`.
- **HTTP layer** — `JsonResponse` factory, `JsonBodyMiddleware`, `AuthMiddleware` (Bearer + scopes, 401/403), `CorsMiddleware` (preflight + headers), `RateLimitMiddleware` (token-bucket per key/IP, `X-RateLimit-*` headers), `AuditMiddleware` (`X-Response-Time-ms` + analytics events).
- **Controllers (PSR-15)** — `SearchGatewayController` (dispatches 7 gateway actions), `AdminController` (10 admin endpoints), `StreamController` (SSE), `MetricsController` (`/metrics`).
- **API key management** — `ApiKeyInterface`, `ApiKey` (immutable, wildcard `*` scope), `ApiKeyHasher` (`sgw_` prefix, bcrypt, public prefix redaction), `InMemoryApiKeyStore`, `FileApiKeyStore` (atomic JSON with `flock` + `chmod 0o600`), `ApiKeyManager` (`create()` / `verify()` / `authenticate()` returning 401/403).
- **Plugin pipeline** — `PluginInterface` (before/after), `PluginContext` (DI: logger, metrics, cache, analytics, formatter), `PluginPipeline`, built-in `LoggingPlugin`, `MetricsPlugin`, `CacheKeyPlugin`.
- **Admin plane** — `AdminAuth` (`SGW_ADMIN_TOKEN` env, role-based: `admin:super`, `admin:routes`, `admin:keys`, `admin:read`), `RouteAdminService`, `KeyAdminService`, `HealthAdminService`, `AnalyticsAdminService`.
- **Streaming** — `SseEmitter` (SSE format with keep-alive + done/error events), `StreamController` (route-aware SSE dispatch).
- **Observability** — `InMemoryMetrics` (counter / gauge / timing + p50/p95/p99), `PrometheusExporter` (text format 0.0.4), `AuditLoggerInterface`, `InMemoryAuditLogger`, `FileAuditLogger` (atomic JSON-lines with `flock` + size-based truncation).
- **OpenAPI & portal** — `OpenApiGenerator` (OpenAPI 3.0.3 from registry), `PortalController` (`/docs`, `/docs/openapi.json`, `/docs/sandbox`, `/docs/portal` with Swagger UI 5.17.14 via CDN).
- **Composability** — `SearchRequest::withPathParams()`, `SearchResponse::withMeta()` / `withMetaValue()` / `withStatus()` (immutable DTO transformations).
- **Composer** — added `psr/http-message`, `psr/http-server-handler` to `require`; `nyholm/psr7`, `nyholm/psr7-server`, `psr/http-server-middleware` to `require-dev`; suggest block for optional HTTP/YAML/Redis/Predis.

### Tests

- **372 PHPUnit tests** / **951 assertions** (2 skipped)
- Universal API added **~150 new tests** (107 → 372):
  - Router: 58 (Route, Registry, PathMatcher, Resolver, ConfigLoader, Presets)
  - ApiKey: 42
  - Plugin: 28
  - Middleware: ~30
  - Controllers: ~24
  - Admin services: ~25
  - Portal: 20
  - Streaming: 12
  - Observability: 16
  - Request DTOs: 12

### Documentation

- `docs/UNIVERSAL_API.md` — full reference (10 KB, 9 sections)
- `examples/universal-api-quickstart.php` — runnable PSR-7/15 router (verified end-to-end with `php -S`)
- `examples/standalone-demo.php` — API key + presets + config loader
- `examples/routes.json` + `examples/routes.yaml` — sample route configs
- `README.md` §19 — Universal API quick reference

## [1.0.0] — 2025-XX-XX

### Added

- Production-grade `SearchGateway\Tool\MultiSearchGateway` with 10 providers (Yandex, Google CSE, Brave, SerpAPI, DuckDuckGo, Bing, Tavily, You.com, Exa, Wikipedia), hybrid LLM providers (OpenAI, Anthropic, Gemini, Ollama), adapters for phpredis and Predis.
- LLM ecosystem: `AdaptiveRetriever`, `CrossEncoderReranker`, `SearchGuardrails` (noPii, noHallucinations, sourceRequired, charLimit, profanityFilter, languageFilter, jailbreakGuard).
- Resilience: `CircuitBreaker` interface with `InMemoryCircuitBreaker` and `RedisCircuitBreaker` (Redis Lua scripts: `allowRequest`, `recordFailure`, `recordSuccess`).
- Observability: `SearchAnalytics`, `SearchVersioning` (hash-keyed snapshots), `SearchExperiment` (A/B buckets).
- Throttling: token-bucket + sliding-window limiters.
- Health & config: `HealthChecker`, `ConfigValidator`.
- `GatewayBuilder` fluent API with full BC surface.
- PSR-12 + PHPCS (lineLimit=140) + PHPStan level 9.
- 107 PHPUnit tests (264 assertions, 2 skipped).
- `README.md` (1050 lines), `INSTALL.md` (12 KB), CI/CD GitHub Actions (3 workflows: ci, cd, codeql).
- `tools/ci-verify.php` — standalone verifier (74/0/3).
- `Dockerfile` + `docker-compose.yml` for containerized deployment.

[Unreleased]: https://github.com/nanocubit/search-gateway/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/nanocubit/search-gateway/releases/tag/v1.0.0
