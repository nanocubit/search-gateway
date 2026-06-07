<?php

declare(strict_types=1);

namespace SearchGateway\Integration\Laravel;

use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\ServiceProvider;
use SearchGateway\Builder\GatewayBuilder;
use SearchGateway\Contract\AsyncHttpClientInterface;
use SearchGateway\Contract\CircuitBreakerInterface;
use SearchGateway\Contract\RedisClientInterface;
use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Contract\StreamingLLMClientInterface;
use SearchGateway\Infrastructure\GuzzleConcurrentHttpClient;
use SearchGateway\Infrastructure\HttpClientInterface;
use SearchGateway\Infrastructure\PhpRedisClientAdapter;
use SearchGateway\Infrastructure\PredisClientAdapter;
use SearchGateway\LLM\OllamaLLMClient;
use SearchGateway\Resilience\InMemoryCircuitBreaker;
use SearchGateway\Resilience\RedisCircuitBreaker;
use SearchGateway\Streaming\StreamingSearchGateway;
use SearchGateway\Tool\SearchTool;

/**
 * Laravel service provider for automatic DI wiring.
 * Publish config: php artisan vendor:publish --tag=search-gateway
 */
class SearchGatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/search-gateway.php', 'search-gateway');

        $this->app->bind(RedisClientInterface::class, function ($app) {
            $config = $app['config']['search-gateway']['circuit_breaker'];
            $driver = $config['redis_client'] ?? 'phpredis';

            if ($driver === 'predis' && class_exists(\Predis\Client::class)) {
                return new PredisClientAdapter($app->make('redis')->connection()->client());
            }

            if (extension_loaded('redis')) {
                $r = new \Redis();
                $host = env('REDIS_HOST', '127.0.0.1');
                $port = (int) env('REDIS_PORT', 6379);
                $r->connect($host, $port);
                return new PhpRedisClientAdapter($r);
            }

            throw new \RuntimeException('No Redis driver available. Install ext-redis or predis/predis.');
        });

        $this->app->bind(CircuitBreakerInterface::class, function ($app) {
            $config = $app['config']['search-gateway']['circuit_breaker'];
            if (($config['driver'] ?? 'memory') === 'redis') {
                return new RedisCircuitBreaker(
                    $app->make(RedisClientInterface::class),
                    $config['name'],
                    (int) $config['threshold'],
                    (int) $config['timeout'],
                    (int) $config['half_open_max_calls'],
                );
            }
            return new InMemoryCircuitBreaker(
                $config['name'],
                (int) $config['threshold'],
                (int) $config['timeout'],
                (int) $config['half_open_max_calls'],
            );
        });

        $this->app->bind(AsyncHttpClientInterface::class, function ($app) {
            $config = $app['config']['search-gateway']['async'];
            if (($config['driver'] ?? 'guzzle') !== 'guzzle') {
                throw new \RuntimeException('Only guzzle async driver is supported in this version.');
            }
            $guzzle = new GuzzleClient([
                'timeout' => (float) ($config['timeout'] ?? 5.0),
                'http_errors' => false,
            ]);
            return new GuzzleConcurrentHttpClient(
                $guzzle,
                $app->make(\Psr\Log\LoggerInterface::class),
                (int) ($config['concurrency'] ?? 5),
            );
        });

        $this->app->bind(OllamaLLMClient::class, function ($app) {
            $config = $app['config']['search-gateway']['ollama'];
            $guzzle = new GuzzleClient(['timeout' => 60.0, 'http_errors' => false]);
            return new OllamaLLMClient(
                $app->make(HttpClientInterface::class),
                $config['base_uri'],
                $config['model'],
                $guzzle,
            );
        });

        $this->app->bind(StreamingLLMClientInterface::class, function ($app) {
            $config = $app['config']['search-gateway']['ollama'];
            if (!($config['enabled'] ?? false)) {
                throw new \RuntimeException('Ollama is not enabled in config/search-gateway.php');
            }
            return $app->make(OllamaLLMClient::class);
        });

        $this->app->singleton(SearchGatewayInterface::class, function ($app) {
            $config = $app['config']['search-gateway'];
            $builder = (new GatewayBuilder())
                ->addYandex($app->make($config['yandex']['client_class']));

            if ($config['brave']['enabled'] ?? false) {
                $builder->addBrave(
                    $app->make(HttpClientInterface::class),
                    $config['brave']['api_key'],
                    $config['brave']['base_uri'] ?? null
                );
            }

            if ($config['cache']['enabled'] ?? false) {
                $builder->withCache(
                    $app->make(\SearchGateway\Infrastructure\CacheInterface::class),
                    (int) $config['cache']['ttl']
                );
            }
            if ($config['retry']['enabled'] ?? false) {
                $builder->withRetry(
                    (int) $config['retry']['retries'],
                    (int) $config['retry']['delay_ms']
                );
            }
            if ($config['metrics']['enabled'] ?? false) {
                $builder->withMetrics($app->make(\SearchGateway\Infrastructure\MetricsInterface::class));
            }
            if ($config['circuit_breaker']['driver'] ?? 'memory') {
                $builder->withCircuitBreakerInterface($app->make(CircuitBreakerInterface::class));
            }

            if ($config['ollama']['enabled'] ?? false) {
                $builder->withLLMAnswer(
                    $app->make(OllamaLLMClient::class),
                    $config['ollama']['system_prompt'] ?? ''
                );
            }

            return $builder->build();
        });

        $this->app->singleton(SearchTool::class, fn ($app) => new SearchTool($app->make(SearchGatewayInterface::class)));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/config/search-gateway.php' => config_path('search-gateway.php'),
            ], 'search-gateway');
        }
    }
}
