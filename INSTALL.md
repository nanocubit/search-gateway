# Установка и запуск Search Gateway

Пошаговое руководство по установке, настройке и запуску проекта на разных платформах.

---

## 1. Системные требования

| Компонент | Минимум | Рекомендуется |
|-----------|---------|---------------|
| **PHP** | 8.2 | 8.3 или 8.4 |
| **Composer** | 2.6 | 2.7+ |
| **ext-curl** | требуется | для Guzzle и Ollama |
| **ext-openssl** | требуется | для HTTPS |
| **ext-redis** *(опц.)* | — | для распределённого circuit breaker |
| **Redis** *(опц.)* | 6.x | 7.x, для `RedisCircuitBreaker` |
| **ext-sockets** *(опц.)* | — | для продвинутых HTTP-клиентов |

> **Примечание:** ядро библиотеки и in-memory circuit breaker работают **без Redis**.
> Redis нужен только для `RedisCircuitBreaker` (распределённый режим) и
> `RedisVectorStore` (векторный поиск).

---

## 2. Клонирование

```bash
git clone https://github.com/nanocubit/search-gateway.git
cd search-gateway
```

---

## 3. Установка зависимостей

### 3.1. Linux (Debian / Ubuntu)

```bash
sudo apt-get update
sudo apt-get install -y \
    php8.2-cli php8.2-curl php8.2-openssl php8.2-mbstring php8.2-xml \
    php8.2-redis php8.2-sockets

php -r "echo PHP_VERSION;"
# 8.2.x — ОК

# Composer (если не установлен)
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Установка dev-зависимостей
composer install
```

### 3.2. macOS (Homebrew)

```bash
brew install php@8.2 composer

brew services start redis       # Опционально, для RedisCircuitBreaker

composer install
```

### 3.3. Windows (PowerShell)

```powershell
# 1. PHP 8.2 — через WinGet (включает ext-curl, ext-openssl по умолчанию)
winget install PHP.PHP.8.2

# 2. Composer
winget install Composer.Composer

# 3. ext-redis (НЕ входит в стандартную сборку PHP for Windows)
#    Скачайте DLL, соответствующую вашей версии PHP (NTS / X64):
#    https://pecl.php.net/package/redis
#    Скопируйте php_redis.dll в C:\php\ext\ и добавьте в php.ini:
#    extension=redis

# 4. Установка dev-зависимостей
composer install
```

### 3.4. Docker (рекомендуется для CI)

```bash
docker run --rm -v "$PWD":/app -w /app php:8.2-cli composer install
docker run --rm -v "$PWD":/app -w /app php:8.2-cli bash -c "\
    apt-get update && apt-get install -y git unzip && \
    docker-php-ext-install sockets && \
    vendor/bin/phpunit"
```

---

## 4. Проверка установки

```bash
# Версия PHP
php --version

# Версия Composer
composer --version

# Проверка расширений
php -m | grep -E "curl|openssl|redis|sockets|mbstring|xml"
```

Ожидаемый вывод:

```
curl
openssl
mbstring
xml
sockets
redis            # опционально
```

---

## 5. Конфигурация

### 5.1. Переменные окружения

Создайте `.env` (или скопируйте `.env.example`, если он появится) в корне проекта:

```env
# ────────────── Search providers ──────────────
YANDEX_SEARCH_ENABLED=false
BRAVE_SEARCH_ENABLED=true
BRAVE_API_KEY=BSA-xxxxxxxxxxxxxxxx
PERPLEXITY_API_KEY=pplx-xxxxxxxxxxxxxxxx
BING_SEARCH_KEY=xxxxxxxxxxxxxxxx

# ────────────── Resilience ──────────────
SEARCH_CIRCUIT_BREAKER_DRIVER=memory
SEARCH_CIRCUIT_BREAKER_NAME=search
SEARCH_CIRCUIT_BREAKER_THRESHOLD=5
SEARCH_CIRCUIT_BREAKER_TIMEOUT=30
SEARCH_CIRCUIT_BREAKER_HALF_OPEN_MAX=3

# ────────────── Async / concurrency ──────────────
SEARCH_ASYNC_ENABLED=true
SEARCH_ASYNC_DRIVER=guzzle
SEARCH_ASYNC_CONCURRENCY=5
SEARCH_ASYNC_TIMEOUT=5.0

# ────────────── Redis (опц.) ──────────────
REDIS_DSN=redis://127.0.0.1:6379/0

# ────────────── Ollama (опц., local LLM) ──────────────
OLLAMA_ENABLED=false
OLLAMA_BASE_URI=http://localhost:11434
OLLAMA_MODEL=llama3.2

# ────────────── Caching / retry / metrics ──────────────
SEARCH_CACHE_ENABLED=true
SEARCH_CACHE_TTL=3600
SEARCH_RETRY_ENABLED=true
SEARCH_METRICS_ENABLED=false
```

### 5.2. Загрузка .env в PHP

```php
// В вашем bootstrap-файле (или используйте vlucas/phpdotenv)
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();
```

---

## 6. Запуск тестов

### 6.1. Полный прогон всех ворот

```bash
# PHPUnit (52 теста, 1 skip на multi-worker saturation)
vendor/bin/phpunit

# PHPCS PSR-12 (lineLimit=140)
vendor/bin/phpcs src tests --standard=phpcs.xml

# PHPStan level 9
php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress

# Standalone verifier (без vendor, нужен только PHP)
php tools/ci-verify.php
```

Или через Makefile:

```bash
make test        # PHPUnit
make style       # PHPCS
make analyse     # PHPStan
make verify      # standalone
make ci          # полный pipeline
```

### 6.2. Ожидаемый результат

```
PHPUnit   : OK (52 tests, 105 assertions, 1 skipped)
PHPCS     : 0 errors, 0 warnings
PHPStan   : [OK] No errors
verify.php: Pass: 74, Fail: 0, Skip: 3
```

### 6.3. Запуск отдельного теста

```bash
vendor/bin/phpunit --filter testStateMachineOpensAfterThreshold
vendor/bin/phpunit tests/Resilience/InMemoryCircuitBreakerTest.php
```

---

## 7. Запуск примеров

Все примеры лежат в `examples/`. Большинство требует установленных API-ключей
и/или Redis. Mock-режим работает без ключей.

### 7.1. Mock-режим (без внешних зависимостей)

```bash
php examples/basic.php
```

### 7.2. Builder (production-grade)

```bash
# Перед запуском установите BRAVE_API_KEY в окружении
export BRAVE_API_KEY=BSA-xxxxxxxx
php examples/builder.php
```

### 7.3. Redis Circuit Breaker

```bash
# Поднимите Redis
docker run -d --rm -p 6379:6379 --name redis-sg redis:7-alpine

php examples/redis_circuit_breaker.php
```

### 7.4. Concurrent HTTP

```bash
export BRAVE_API_KEY=BSA-xxxxxxxx
php examples/concurrent_http.php
```

### 7.5. Ollama (local LLM)

```bash
# Установите Ollama: https://ollama.com/download
ollama pull llama3.2
ollama serve              # в отдельном терминале

export OLLAMA_BASE_URI=http://localhost:11434
php examples/ollama_chat.php
php examples/ollama_streaming.php
```

---

## 8. Интеграция в Laravel

```bash
# 1. Добавьте в composer.json проекта
composer require nanocubit/search-gateway

# 2. Опубликуйте конфиг
php artisan vendor:publish --tag=search-gateway-config

# 3. Пропишите ключи в .env вашего Laravel-проекта
echo "BRAVE_API_KEY=BSA-xxxx" >> .env

# 4. Используйте
use SearchGateway\Builder\GatewayBuilder;

$gateway = app(GatewayBuilder::class)->build();
$docs    = $gateway->llmContext('PHP 8.4 JIT performance');
```

ServiceProvider зарегистрирует `GatewayBuilder`, `CircuitBreaker`, `HttpClient`
и `CacheInterface` как singletons в контейнере.

---

## 9. Интеграция в standalone-PHP приложение

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use SearchGateway\Builder\GatewayBuilder;
use SearchGateway\Gateway\BraveSearchGateway;
use SearchGateway\Gateway\MockSearchGateway;
use SearchGateway\Decorator\RetryingSearchGatewayDecorator;
use SearchGateway\Decorator\FallbackSearchGatewayDecorator;
use SearchGateway\Infrastructure\PhpRedisClientAdapter;
use SearchGateway\Resilience\RedisCircuitBreaker;

$guzzle = new Client(['timeout' => 5.0, 'http_errors' => false]);

$redis = new \Redis();
$redis->connect('127.0.0.1', 6379);
$redisAdapter = new PhpRedisClientAdapter($redis);

$cb = new RedisCircuitBreaker(
    $redisAdapter,
    name: 'search',
    failureThreshold: 5,
    recoveryTimeoutSeconds: 30,
    halfOpenMaxCalls: 3,
);

$gateway = (new GatewayBuilder())
    ->addBrave($guzzle, $_ENV['BRAVE_API_KEY'])
    ->withCache($redisAdapter, 3600)
    ->withRetry(2, 150)
    ->withRedisCircuitBreaker($redisAdapter, 'search')
    ->withFallback(new MockSearchGateway())
    ->build();

$ctx = $gateway->llmContext('Quantum computing 2026');
print_r($ctx);
```

---

## 10. Troubleshooting

### `Class "SearchGateway\..." not found`

```bash
# Перегенерируйте autoloader
composer dump-autoload
```

### `Call to undefined function curl_init()`

```bash
# Linux
sudo apt-get install php8.2-curl
sudo systemctl restart php8.2-fpm   # если используется FPM

# Windows: раскомментируйте extension=curl в php.ini
```

### `Redis not available` при запуске примеров

`RedisCircuitBreaker` и `RedisVectorStore` — опциональны. Если Redis не нужен,
используйте `InMemoryCircuitBreaker`:

```php
use SearchGateway\Resilience\InMemoryCircuitBreaker;
$cb = new InMemoryCircuitBreaker('search', failureThreshold: 5, recoveryTimeoutSeconds: 30);
```

### PHPStan падает с `Allowed memory size exhausted`

```bash
php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress --memory-limit=2G
```

### PHPUnit пишет `S` (skipped) на Redis-тестах

Это нормально. Тесты, требующие `ext-redis` или `predis/predis`, автоматически
пропускаются, если расширения/пакеты не установлены. Логика покрыта
`tools/ci-verify.php` (тоже SKIP, не FAIL).

### Ollama возвращает 404 на `/api/chat`

Убедитесь, что модель поддерживает chat:

```bash
ollama pull llama3.2
# Не все модели имеют chat endpoint (например, embedding-модели)
```

### Windows: `redis.dll` не загружается

- Проверьте, что DLL соответствует **версии PHP** (8.2 / 8.3 / 8.4) и архитектуре (NTS / X64).
- Положите `php_redis.dll` в `C:\php\ext\` (или где у вас `extension_dir`).
- В `php.ini` добавьте `extension=redis`.
- Перезапустите CLI: `php -m | grep redis` должен показать `redis`.

---

## 11. Проверка после установки

```bash
make ci
```

Если все 4 ворота зелёные — установка прошла успешно, проект готов к работе.

| Ворота | Ожидаемый результат |
|--------|---------------------|
| `make test` | `OK (372 tests, 951 assertions, 2 skipped)` |
| `make style` | `0 errors, 0 warnings` |
| `make analyse` | `[OK] No errors` |
| `make verify` | `Pass: 74, Fail: 0, Skip: 3` |

---

## 12. Universal API — быстрый запуск

Universal API превращает библиотеку в полноценный HTTP-шлюз. Полная документация: [`docs/UNIVERSAL_API.md`](docs/UNIVERSAL_API.md).

### 12.1. Запуск примера через встроенный PHP-сервер

```bash
SGW_ADMIN_TOKEN=dev-token php -S 127.0.0.1:8080 -t examples examples/universal-api-quickstart.php
```

### 12.2. Проверка endpoint-ов

```bash
# Swagger UI
curl http://127.0.0.1:8080/docs

# OpenAPI 3.0.3 spec
curl http://127.0.0.1:8080/docs/openapi.json

# Prometheus metrics
curl http://127.0.0.1:8080/metrics

# Admin health (требуется Bearer token)
curl -H "Authorization: Bearer dev-token" http://127.0.0.1:8080/admin/health

# Admin routes
curl -H "Authorization: Bearer dev-token" http://127.0.0.1:8080/admin/routes
```

### 12.3. Создание API-ключа

```bash
php examples/standalone-demo.php
# API key (use as Bearer token): sgw_...
# Key id: ..., scopes: search:web
```

### 12.4. Конфигурация через файл

Создайте `routes.yaml`:

```yaml
routes:
  - name: v1.search.web
    method: POST
    path: /v1/search/web
    action: searchWeb
    requiredScopes: [search:web]
    rateLimit: {limit: 100, window: 60}
```

Затем в коде:

```php
$loader = new RouteConfigLoader();
foreach ($loader->loadFromFile('routes.yaml') as $route) {
    $registry->register($route);
}
```

> Для YAML требуется `composer require symfony/yaml` (или реализуйте `YamlParserInterface`).

### 12.5. Production-развёртывание

Universal API работает с любым PSR-15-совместимым фреймворком (Laravel, Symfony, Slim, Laminas, Mezzio). Пример для Slim 4:

```php
use Slim\Factory\AppFactory;
use SearchGateway\Controller\SearchGatewayController;
use SearchGateway\Http\Middleware\AuthMiddleware;
use SearchGateway\Http\Middleware\JsonBodyMiddleware;
use SearchGateway\Http\Middleware\RateLimitMiddleware;
use SearchGateway\Http\Middleware\AuditMiddleware;

$app = AppFactory::create();
$controller = new SearchGatewayController(/* ... */);

$app->add(new CorsMiddleware());
$app->add(new JsonBodyMiddleware());
$app->add(new RateLimitMiddleware(['limit' => 100, 'window' => 60]));
$app->add(new AuditMiddleware($analytics));
$app->add(new AuthMiddleware($apiKeyManager));

$app->post('/v1/search/web', [$controller, 'handle']);
$app->run();
```

---

## 13. Дальнейшие шаги

- Прочитайте [README.md](README.md) — обзор архитектуры и API
- Прочитайте [docs/UNIVERSAL_API.md](docs/UNIVERSAL_API.md) — полная документация Universal API
- Посмотрите `examples/` — рабочие сценарии (PSR-7/15 router, standalone demo, YAML/JSON конфиги)
- Изучите `src/Builder/GatewayBuilder.php` — fluent API для production-grade цепочек
- Изучите `src/Controller/SearchGatewayController.php` — HTTP dispatch + plugin pipeline
- При проблемах — откройте issue с выводом `make ci`
