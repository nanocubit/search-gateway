<?php

declare(strict_types=1);

namespace SearchGateway\Builder;

use GuzzleHttp\Client;
use SearchGateway\Contract\AsyncHttpClientInterface;
use SearchGateway\Contract\CircuitBreakerInterface;
use SearchGateway\Contract\RedisClientInterface;
use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Contract\StreamingLLMClientInterface;
use SearchGateway\Decorator\CachedSearchGatewayDecorator;
use SearchGateway\Decorator\CircuitBreakerSearchGatewayDecorator;
use SearchGateway\Decorator\FallbackSearchGatewayDecorator;
use SearchGateway\Decorator\LLMAnswerSearchGatewayDecorator;
use SearchGateway\Decorator\LoggerSearchGatewayDecorator;
use SearchGateway\Decorator\MetricsSearchGatewayDecorator;
use SearchGateway\Decorator\RateLimitedSearchGatewayDecorator;
use SearchGateway\Decorator\RetryingSearchGatewayDecorator;
use SearchGateway\Gateway\BraveSearchGateway;
use SearchGateway\Gateway\YandexCloudSearchGateway;
use SearchGateway\Infrastructure\CacheInterface;
use SearchGateway\Infrastructure\GuzzleConcurrentHttpClient;
use SearchGateway\Infrastructure\HttpClientInterface;
use SearchGateway\Infrastructure\LLMClientInterface;
use SearchGateway\Infrastructure\LoggerInterface;
use SearchGateway\Infrastructure\MetricsInterface;
use SearchGateway\Infrastructure\RateLimiterInterface;
use SearchGateway\LLM\OllamaLLMClient;
use SearchGateway\Resilience\InMemoryCircuitBreaker;
use SearchGateway\Resilience\RedisCircuitBreaker;
use SearchGateway\Streaming\StreamingSearchGateway;
use SearchGateway\Tool\AsyncMultiSearchGateway;
use SearchGateway\Tool\MultiSearchGateway;

/**
 * Fluent builder for constructing decorated search gateways from config.
 */
class GatewayBuilder
{
    /** @var list<SearchGatewayInterface> */
    private array $providers = [];
    private ?CacheInterface $cache = null;
    private ?MetricsInterface $metrics = null;
    private ?LoggerInterface $logger = null;
    private ?RateLimiterInterface $rateLimiter = null;
    private ?CircuitBreakerInterface $circuitBreaker = null;
    /** @var list<SearchGatewayInterface> */
    private array $fallbacks = [];
    private bool $retry = false;
    private int $retryCount = 2;
    private int $retryDelayMs = 150;
    private ?int $cacheTtl = null;
    private ?string $rateLimitKey = null;
    private ?int $rateLimitMax = null;
    private ?int $rateLimitWindow = null;
    private ?AsyncHttpClientInterface $asyncClient = null;
    private ?LLMClientInterface $llm = null;
    private ?string $llmSystemPrompt = null;
    private ?StreamingLLMClientInterface $streamingLlm = null;

    public function addYandex(object $client): self
    {
        $this->providers[] = new YandexCloudSearchGateway($client);
        return $this;
    }

    public function addBrave(HttpClientInterface $http, string $apiKey, string $baseUri = 'https://api.search.brave.com/res/v1'): self
    {
        $this->providers[] = new BraveSearchGateway($http, $apiKey, $baseUri);
        return $this;
    }

    public function addProvider(SearchGatewayInterface $gateway): self
    {
        $this->providers[] = $gateway;
        return $this;
    }

    public function withCache(CacheInterface $cache, int $ttlSeconds = 3600): self
    {
        $this->cache = $cache;
        $this->cacheTtl = $ttlSeconds;
        return $this;
    }

    public function withMetrics(MetricsInterface $metrics): self
    {
        $this->metrics = $metrics;
        return $this;
    }

    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    public function withRateLimit(RateLimiterInterface $limiter, string $providerKey, int $max = 60, int $window = 60): self
    {
        $this->rateLimiter = $limiter;
        $this->rateLimitKey = $providerKey;
        $this->rateLimitMax = $max;
        $this->rateLimitWindow = $window;
        return $this;
    }

    public function withCircuitBreaker(string $name, int $threshold = 5, int $timeout = 30, int $halfOpenMaxCalls = 3): self
    {
        $this->circuitBreaker = new InMemoryCircuitBreaker($name, $threshold, $timeout, $halfOpenMaxCalls);
        return $this;
    }

    public function withCircuitBreakerInterface(CircuitBreakerInterface $breaker): self
    {
        $this->circuitBreaker = $breaker;
        return $this;
    }

    public function withRedisCircuitBreaker(
        RedisClientInterface $redis,
        string $name,
        int $threshold = 5,
        int $timeout = 30,
        int $halfOpenMaxCalls = 3,
    ): self {
        $this->circuitBreaker = new RedisCircuitBreaker($redis, $name, $threshold, $timeout, $halfOpenMaxCalls);
        return $this;
    }

    public function withFallback(SearchGatewayInterface ...$gateways): self
    {
        $this->fallbacks = array_values($gateways);
        return $this;
    }

    public function withRetry(int $count = 2, int $delayMs = 150): self
    {
        $this->retry = true;
        $this->retryCount = $count;
        $this->retryDelayMs = $delayMs;
        return $this;
    }

    public function withAsyncClient(AsyncHttpClientInterface $client): self
    {
        $this->asyncClient = $client;
        return $this;
    }

    public function withGuzzleConcurrentClient(
        Client $guzzle,
        LoggerInterface $logger,
        int $concurrency = 5,
    ): self {
        $this->asyncClient = new GuzzleConcurrentHttpClient($guzzle, $logger, $concurrency);
        return $this;
    }

    public function withLLMAnswer(LLMClientInterface $llm, string $systemPrompt = ''): self
    {
        $this->llm = $llm;
        $this->llmSystemPrompt = $systemPrompt;
        return $this;
    }

    public function withStreamingLLM(StreamingLLMClientInterface $llm): self
    {
        $this->streamingLlm = $llm;
        return $this;
    }

    public function withOllamaLLM(
        HttpClientInterface $http,
        string $baseUri = 'http://localhost:11434',
        string $model = 'llama3.2',
        ?Client $streamingGuzzle = null,
    ): self {
        $llm = new OllamaLLMClient($http, $baseUri, $model, $streamingGuzzle);
        $this->llm = $llm;
        $this->streamingLlm = $llm;
        return $this;
    }

    public function build(): SearchGatewayInterface
    {
        $gateway = $this->composeCore();

        if ($this->llm !== null) {
            $gateway = new LLMAnswerSearchGatewayDecorator(
                $gateway,
                $this->llm,
                $this->llmSystemPrompt ?? '',
            );
        }

        return $gateway;
    }

    /**
     * Build a StreamingSearchGateway using the configured streaming LLM.
     * Throws when no streaming LLM is configured.
     */
    public function buildStreamer(): StreamingSearchGateway
    {
        if ($this->streamingLlm === null) {
            throw new \LogicException(
                'buildStreamer() requires withStreamingLLM() or withOllamaLLM() to be called first.'
            );
        }
        return new StreamingSearchGateway($this->composeCore(), $this->streamingLlm);
    }

    /**
     * Build an AsyncMultiSearchGateway using the configured async client.
     * Throws when no async client is configured.
     */
    public function buildMultiGateway(): AsyncMultiSearchGateway
    {
        if ($this->asyncClient === null) {
            throw new \LogicException(
                'buildMultiGateway() requires withAsyncClient() or withGuzzleConcurrentClient() to be called first.'
            );
        }

        $core = $this->composeCore();
        return new AsyncMultiSearchGateway([$core], $this->asyncClient);
    }

    private function composeCore(): SearchGatewayInterface
    {
        $gateway = count($this->providers) === 1
            ? $this->providers[0]
            : new MultiSearchGateway($this->providers);

        return $this->decorate($gateway);
    }

    private function decorate(SearchGatewayInterface $gateway): SearchGatewayInterface
    {
        if ($this->fallbacks !== []) {
            $gateway = new FallbackSearchGatewayDecorator($gateway, $this->fallbacks);
        }

        if ($this->circuitBreaker !== null) {
            $gateway = new CircuitBreakerSearchGatewayDecorator($gateway, $this->circuitBreaker);
        }

        if ($this->rateLimiter !== null) {
            $gateway = new RateLimitedSearchGatewayDecorator(
                $gateway,
                $this->rateLimiter,
                $this->rateLimitKey ?? 'default',
                $this->rateLimitMax ?? 60,
                $this->rateLimitWindow ?? 60
            );
        }

        if ($this->retry) {
            $gateway = new RetryingSearchGatewayDecorator($gateway, $this->retryCount, $this->retryDelayMs);
        }

        if ($this->cache !== null) {
            $gateway = new CachedSearchGatewayDecorator($gateway, $this->cache, $this->cacheTtl ?? 3600);
        }

        if ($this->metrics !== null) {
            $gateway = new MetricsSearchGatewayDecorator($gateway, $this->metrics);
        }

        if ($this->logger !== null) {
            $gateway = new LoggerSearchGatewayDecorator($gateway, $this->logger);
        }

        return $gateway;
    }
}
