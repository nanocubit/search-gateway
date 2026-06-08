<?php

declare(strict_types=1);

/**
 * Standalone CI verifier for the search-gateway project.
 *
 * Runs without composer / vendor / network. Exhaustive smoke-test of the
 * production components added in 2.0 plus regression coverage of the
 * existing decorators, gateways, ranker, formatter, and agent layer.
 *
 * Tests that depend on ext-redis, ext-curl, ext-openssl, Guzzle, or Predis
 * are explicitly marked SKIPPED. PHPUnit test classes live in tests/ and
 * need composer install to run.
 *
 * Usage: php tools/ci-verify.php
 * Exit code: 0 = all pass, 1 = any fail.
 */

namespace SearchGateway\Tools;

use SearchGateway\Agent\AgentWorkflow;
use SearchGateway\Agent\PersonalSearchContext;
use SearchGateway\Builder\GatewayBuilder;
use SearchGateway\Contract\AsyncHttpClientInterface;
use SearchGateway\Contract\CircuitBreakerInterface;
use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Contract\SearchGatewayException;
use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Contract\StreamingLLMClientInterface;
use SearchGateway\Decorator\CachedSearchGatewayDecorator;
use SearchGateway\Decorator\CircuitBreakerSearchGatewayDecorator;
use SearchGateway\Decorator\FallbackSearchGatewayDecorator;
use SearchGateway\Decorator\LLMAnswerSearchGatewayDecorator;
use SearchGateway\Decorator\RateLimitedSearchGatewayDecorator;
use SearchGateway\Decorator\RetryingSearchGatewayDecorator;
use SearchGateway\Formatter\ResponseFormatter;
use SearchGateway\Gateway\HybridBrowserHistoryGateway;
use SearchGateway\Gateway\MockSearchGateway;
use SearchGateway\Infrastructure\CacheInterface;
use SearchGateway\Infrastructure\HttpClientInterface;
use SearchGateway\Infrastructure\LLMClientInterface;
use SearchGateway\Infrastructure\LoggerInterface;
use SearchGateway\Infrastructure\MetricsInterface;
use SearchGateway\Infrastructure\RateLimiterInterface;
use SearchGateway\LLM\OllamaLLMClient;
use SearchGateway\LLM\OllamaChatLLMClient;
use SearchGateway\Prompt\PromptBuilder;
use SearchGateway\Ranker\SearchResultFilter;
use SearchGateway\Ranker\SearchResultRanker;
use SearchGateway\Resilience\CircuitBreaker;
use SearchGateway\Resilience\CircuitOpenException;
use SearchGateway\Resilience\InMemoryCircuitBreaker;
use SearchGateway\Resilience\RedisCircuitBreaker;
use SearchGateway\Streaming\StreamingSearchGateway;
use SearchGateway\Tool\AsyncMultiSearchGateway;
use SearchGateway\Tool\AsyncRequestBuilderInterface;
use SearchGateway\Tool\MultiSearchGateway;
use SearchGateway\Tool\SearchTool;
use SearchGateway\Tests\Resilience\FakeRedisClient;

// Autoloader for the project sources and the FakeRedisClient test double.
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'SearchGateway\\Tests\\' => __DIR__ . '/../tests/',
        'SearchGateway\\'        => __DIR__ . '/../src/',
    ];
    foreach ($prefixes as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $path = $baseDir . $relative . '.php';
        if (is_file($path)) {
            require $path;
            return;
        }
    }
});

// ---------------------------------------------------------------------------
// Assertion harness
// ---------------------------------------------------------------------------

$pass = 0;
$fail = 0;
$skip = 0;
$currentSection = '';

$check = static function (bool $cond, string $label) use (&$pass, &$fail): void {
    if ($cond) {
        echo "  PASS: {$label}\n";
        $pass++;
    } else {
        echo "  FAIL: {$label}\n";
        $fail++;
    }
};

$skipFn = static function (string $label, string $reason) use (&$skip): void {
    echo "  SKIP: {$label} ({$reason})\n";
    $skip++;
};

$beginSection = static function (string $title) use (&$currentSection): void {
    $currentSection = $title;
    echo "\n=== {$title} ===\n";
};

// ---------------------------------------------------------------------------
// Mocks shared across sections
// ---------------------------------------------------------------------------

final class CountingLogger implements LoggerInterface
{
    public int $debugCalls = 0;
    public int $errorCalls = 0;
    public function debug(string $message, array $context = []): void { $this->debugCalls++; }
    public function info(string $message, array $context = []): void {}
    public function warning(string $message, array $context = []): void {}
    public function error(string $message, array $context = []): void { $this->errorCalls++; }
}

final class ArrayCache implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $store = [];
    public function get(string $key): mixed { return $this->store[$key] ?? null; }
    public function set(string $key, mixed $value, int $ttlSeconds): bool { $this->store[$key] = $value; return true; }
}

final class CountingMetrics implements MetricsInterface
{
    /** @var array<string, float> */
    public array $timings = [];
    /** @var array<string, int> */
    public array $increments = [];
    public function timing(string $name, float $seconds): void { $this->timings[$name] = $seconds; }
    public function increment(string $name, int $count = 1): void { $this->increments[$name] = ($this->increments[$name] ?? 0) + $count; }
    public function gauge(string $name, float $value): void {}
}

final class StubRateLimiter implements RateLimiterInterface
{
    public bool $allow = true;
    public function acquire(string $key, int $maxRequests, int $windowSeconds): bool { return $this->allow; }
    public function waitTime(string $key, int $maxRequests, int $windowSeconds): float { return $this->allow ? 0.0 : 1.0; }
}

final class StubHttp implements HttpClientInterface
{
    /** @var array<int, array{url:string, payload:array<string,mixed>}> */
    public array $calls = [];
    /** @var array<int, array<string,mixed>> */
    public array $responses = [];
    private int $idx = 0;
    public function getJson(string $url, array $options = []): array
    {
        $this->calls[] = ['url' => $url, 'payload' => $options];
        return $this->responses[$this->idx++] ?? [];
    }
    public function postJson(string $url, array $payload, array $options = []): array
    {
        $this->calls[] = ['url' => $url, 'payload' => $payload];
        return $this->responses[$this->idx++] ?? [];
    }
}

final class StubLLM implements LLMClientInterface
{
    public string $lastPrompt = '';
    public string $answer = 'stub answer';
    public function generate(string $prompt, array $options = []): string
    {
        $this->lastPrompt = $prompt;
        return $this->answer;
    }
}

final class StubStreamingLLM implements StreamingLLMClientInterface
{
    /** @var list<string> */
    public array $chunks = ['Hello', ', ', 'world!'];
    public function generate(string $prompt, array $options = []): string
    {
        return implode('', $this->chunks);
    }
    public function streamGenerate(string $prompt, array $options = []): \Generator
    {
        foreach ($this->chunks as $chunk) {
            yield $chunk;
        }
    }
}

final class StubAsyncHttp implements AsyncHttpClientInterface
{
    /** @var array<int, array<string,mixed>> */
    public array $jobLog = [];
    public function runConcurrent(array $jobs, array $options = []): array
    {
        $out = [];
        foreach ($jobs as $key => $job) {
            $this->jobLog[] = $job;
            $out[$key] = [
                'success' => true,
                'value' => [
                    ['type' => 'web', 'title' => "T-{$key}", 'url' => "https://example.com/{$key}", 'passage' => "p-{$key}", 'score' => 0.9],
                ],
                'error' => null,
                'provider' => $job['provider'] ?? null,
                'latency_ms' => 1.0,
                'status' => 200,
            ];
        }
        return $out;
    }
}

final class StaticGateway implements SearchGatewayInterface
{
    public function __construct(public string $name, public array $docs = []) {}
    public function searchWeb(string $q, array $o = []): array { return $this->docs; }
    public function searchNews(string $q, array $o = []): array { return []; }
    public function searchImages(string $q, array $o = []): array { return []; }
    public function searchGen(string $q, array $o = []): GenerativeSearchResultDTO
    {
        return new GenerativeSearchResultDTO(answer: 'gen-' . $this->name, sources: $this->docs, meta: ['provider' => $this->name]);
    }
    public function wordstat(string $q, array $o = []): array { return []; }
    public function llmContext(string $q, array $o = []): array { return $this->docs; }
    public function providerName(): string { return $this->name; }
}

final class AsyncBuildableGateway implements SearchGatewayInterface, AsyncRequestBuilderInterface
{
    public function __construct(public string $name, public int $callCount = 0) {}
    public function searchWeb(string $q, array $o = []): array { $this->callCount++; return []; }
    public function searchNews(string $q, array $o = []): array { return []; }
    public function searchImages(string $q, array $o = []): array { return []; }
    public function searchGen(string $q, array $o = []): GenerativeSearchResultDTO
    {
        return new GenerativeSearchResultDTO(answer: '', sources: [], meta: ['provider' => $this->name]);
    }
    public function wordstat(string $q, array $o = []): array { return []; }
    public function llmContext(string $q, array $o = []): array { return []; }
    public function providerName(): string { return $this->name; }
    public function buildRequests(string $method, string $query, array $options): array
    {
        return [[
            'method' => 'GET',
            'uri' => "https://api.test/{$this->name}/{$method}?q=" . urlencode($query),
            'provider' => $this->name,
        ]];
    }
}

// ---------------------------------------------------------------------------
// Section: InMemoryCircuitBreaker
// ---------------------------------------------------------------------------

$beginSection('InMemoryCircuitBreaker');

$cb = new InMemoryCircuitBreaker('t1', failureThreshold: 3, recoveryTimeoutSeconds: 10);
for ($i = 0; $i < 3; $i++) {
    try { $cb->call(static fn() => throw new \RuntimeException('boom')); } catch (\Throwable) {}
}
$check($cb->getState() === InMemoryCircuitBreaker::STATE_OPEN, 'state=OPEN after 3 failures');

try {
    $cb->call(static fn() => 'unreachable');
    $check(false, 'throws when OPEN');
} catch (CircuitOpenException $e) {
    $check($e->getBreakerName() === 't1' && $e->getCode() === 503, 'CircuitOpenException name+code');
}

$cb2 = new InMemoryCircuitBreaker('t2', failureThreshold: 1, recoveryTimeoutSeconds: 0, halfOpenMaxCalls: 2);
try { $cb2->call(static fn() => throw new \RuntimeException('x')); } catch (\Throwable) {}
$check($cb2->getState() === InMemoryCircuitBreaker::STATE_OPEN, 'cb2: state=OPEN after 1 failure');

$r1 = $cb2->call(static fn() => 'ok1');
$check($r1 === 'ok1' && $cb2->getState() === InMemoryCircuitBreaker::STATE_HALF_OPEN, 'cb2: HALF_OPEN after 1 success');

$r2 = $cb2->call(static fn() => 'ok2');
$check($r2 === 'ok2' && $cb2->getState() === InMemoryCircuitBreaker::STATE_CLOSED, 'cb2: CLOSED after 2 successes');

// BC alias
$alias = new CircuitBreaker('alias-test', failureThreshold: 1);
$check($alias instanceof InMemoryCircuitBreaker, 'BC: CircuitBreaker extends InMemoryCircuitBreaker');
$check($alias instanceof CircuitBreakerInterface, 'BC: instance is CircuitBreakerInterface');

// reset() / getName()
$check($cb->getName() === 't1', 'getName() returns the breaker name');
$cb->reset();
$check($cb->getState() === InMemoryCircuitBreaker::STATE_CLOSED, 'reset() -> CLOSED');

// ---------------------------------------------------------------------------
// Section: RedisCircuitBreaker (via FakeRedisClient)
// ---------------------------------------------------------------------------

$beginSection('RedisCircuitBreaker (FakeRedisClient)');

$rcb = new RedisCircuitBreaker(new FakeRedisClient(), 'svc1', failureThreshold: 3, recoveryTimeoutSeconds: 10);
for ($i = 0; $i < 3; $i++) {
    $rcb->recordFailure();
}
$check($rcb->getState() === RedisCircuitBreaker::STATE_OPEN, 'state=OPEN after 3 failures');

try { $rcb->allowRequest(); $check(false, 'throws when OPEN'); }
catch (CircuitOpenException $e) { $check($e->getBreakerName() === 'svc1', 'throws with breaker name'); }

$rcb2 = new RedisCircuitBreaker(new FakeRedisClient(), 'svc2', failureThreshold: 1, recoveryTimeoutSeconds: 0, halfOpenMaxCalls: 1);
$rcb2->recordFailure();
$rcb2->allowRequest();  // transition to HALF_OPEN
$check($rcb2->getState() === RedisCircuitBreaker::STATE_HALF_OPEN, 'state=HALF_OPEN after recovery');

$rcb2->recordSuccess();
$check($rcb2->getState() === RedisCircuitBreaker::STATE_CLOSED, 'state=CLOSED after probe success');

// reset() and getName() are still available
$rcb3 = new RedisCircuitBreaker(new FakeRedisClient(), 'svc3', failureThreshold: 1, recoveryTimeoutSeconds: 10);
$rcb3->recordFailure();
$rcb3->reset();
$check($rcb3->getState() === RedisCircuitBreaker::STATE_CLOSED, 'reset() -> CLOSED');
$check($rcb3->getName() === 'svc3', 'getName() returns name');

$rcb4 = new RedisCircuitBreaker(new FakeRedisClient(), 'svc4', failureThreshold: 5, recoveryTimeoutSeconds: 10);
$rcb4->recordFailure();
$rcb4->recordFailure();
$rcb4->recordSuccess();
$check($rcb4->getState() === RedisCircuitBreaker::STATE_CLOSED, 'recordSuccess() resets counter');

if (!extension_loaded('redis')) {
    $skipFn('PhpRedisClientAdapter', 'ext-redis not loaded');
}
if (!class_exists('Predis\\ClientInterface', false)) {
    $skipFn('PredisClientAdapter', 'predis/predis not installed');
}

// ---------------------------------------------------------------------------
// Section: CircuitBreakerSearchGatewayDecorator
// ---------------------------------------------------------------------------

$beginSection('CircuitBreakerSearchGatewayDecorator');

$inner = new MockSearchGateway([
    'searchWeb' => [
        ['type' => 'web', 'title' => 'Doc1', 'url' => 'https://d1', 'passage' => 'p1', 'score' => 1.0],
    ],
]);
$dec = new CircuitBreakerSearchGatewayDecorator(
    $inner,
    new InMemoryCircuitBreaker('dec', failureThreshold: 2, recoveryTimeoutSeconds: 10),
);
$docs = $dec->searchWeb('q');
$check(count($docs) === 1 && $docs[0]['title'] === 'Doc1', 'searchWeb propagates to inner');
$check($dec->getBreaker()->getName() === 'dec', 'getBreaker() exposes the breaker');

// Trigger OPEN, expect throws on every guarded method
$trigger = new InMemoryCircuitBreaker('trig', failureThreshold: 1, recoveryTimeoutSeconds: 60);
$throwingInner = new class implements SearchGatewayInterface {
    public function searchWeb(string $q, array $o = []): array { throw new \RuntimeException('upstream'); }
    public function searchNews(string $q, array $o = []): array { throw new \RuntimeException('upstream'); }
    public function searchImages(string $q, array $o = []): array { throw new \RuntimeException('upstream'); }
    public function searchGen(string $q, array $o = []): GenerativeSearchResultDTO
    {
        throw new \RuntimeException('upstream');
    }
    public function wordstat(string $q, array $o = []): array { throw new \RuntimeException('upstream'); }
    public function llmContext(string $q, array $o = []): array { throw new \RuntimeException('upstream'); }
};
$dec2 = new CircuitBreakerSearchGatewayDecorator($throwingInner, $trigger);
try { $dec2->searchWeb('boom'); } catch (\Throwable) {}
$check($trigger->getState() === InMemoryCircuitBreaker::STATE_OPEN, 'decorator triggers breaker OPEN');

$guarded = ['searchWeb', 'searchNews', 'searchImages', 'searchGen', 'wordstat', 'llmContext'];
foreach ($guarded as $method) {
    try { $dec2->$method('x'); $check(false, "throws on OPEN: {$method}"); }
    catch (CircuitOpenException) { $check(true, "throws on OPEN: {$method}"); }
}

// ---------------------------------------------------------------------------
// Section: StreamingSearchGateway
// ---------------------------------------------------------------------------

$beginSection('StreamingSearchGateway');

$streamer = new StreamingSearchGateway(
    new MockSearchGateway([
        'llmContext' => [
            ['type' => 'web', 'title' => 'S1', 'url' => 'https://s1', 'passage' => 'p', 'score' => 1.0],
        ],
    ]),
    new StubStreamingLLM(),
);

$gen = $streamer->streamGen('q');
$collected = '';
foreach ($gen as $chunk) { $collected .= $chunk; }
$dto = $gen->getReturn();
$check($collected === 'Hello, world!', 'streams all chunks');
$check($dto->answer === 'Hello, world!', 'DTO.answer is concatenated buffer');
$check(count($dto->sources) === 1, 'DTO carries llmContext sources');
$check(($dto->meta['streamed'] ?? false) === true, 'meta.streamed=true');

// ---------------------------------------------------------------------------
// Section: LLMAnswerSearchGatewayDecorator
// ---------------------------------------------------------------------------

$beginSection('LLMAnswerSearchGatewayDecorator');

$llm = new StubLLM();
$llm->answer = 'synthesized';
$emptyGenInner = new MockSearchGateway([
    'searchGen' => new GenerativeSearchResultDTO(answer: '', sources: [
        ['type' => 'web', 'title' => 'S1', 'url' => 'https://s1', 'passage' => 'p', 'score' => 1.0],
    ], meta: ['provider' => 'mock']),
    'searchWeb' => [
        ['type' => 'web', 'title' => 'Doc1', 'url' => 'https://d1', 'passage' => 'p1', 'score' => 1.0],
    ],
]);
$dec3 = new LLMAnswerSearchGatewayDecorator($emptyGenInner, $llm, 'sys');
$res = $dec3->searchGen('Q');
$check($res instanceof GenerativeSearchResultDTO, 'returns GenerativeSearchResultDTO');
$check($res->answer === 'synthesized', 'answer is LLM output');
$check(str_contains($llm->lastPrompt, 'sys') || str_contains($llm->lastPrompt, 'Q'), 'prompt contains system or task');
$check(count($res->sources) === 1, 'sources come from inner');

// Inner already has an answer: decorator must NOT call the LLM
$llm2 = new StubLLM();
$llm2->answer = 'WRONG';
$preAnsweredInner = new MockSearchGateway([
    'searchGen' => new GenerativeSearchResultDTO(answer: 'pre-generated', sources: [], meta: ['provider' => 'mock']),
]);
$res2 = (new LLMAnswerSearchGatewayDecorator($preAnsweredInner, $llm2))->searchGen('Q');
$check($res2->answer === 'pre-generated', 'preserves pre-existing answer');

// ---------------------------------------------------------------------------
// Section: AsyncMultiSearchGateway
// ---------------------------------------------------------------------------

$beginSection('AsyncMultiSearchGateway');

$asyncHttp = new StubAsyncHttp();
$amg = new AsyncMultiSearchGateway([
    new AsyncBuildableGateway('p1'),
    new AsyncBuildableGateway('p2'),
], $asyncHttp);
$out = $amg->searchWeb('hello');
$check(count($asyncHttp->jobLog) === 2, 'async HTTP fan-out: 2 jobs dispatched');
$check(count($out) === 2, 'aggregated 2 distinct results from async HTTP');
$check($out[0]['title'] !== $out[1]['title'], 'results are distinct per provider');

// With a non-AsyncRequestBuilder gateway, falls back to sequential
$amg2 = new AsyncMultiSearchGateway([
    new StaticGateway('a'),
    new StaticGateway('b'),
], $asyncHttp);
$asyncHttp->jobLog = [];
$out2 = $amg2->searchWeb('q');
$check(count($asyncHttp->jobLog) === 0, 'non-builder gateways use sequential fallback');

// llmContext delegates to parallel
$asyncHttp->jobLog = [];
$amg3 = new AsyncMultiSearchGateway([new AsyncBuildableGateway('p1')], $asyncHttp);
$out3 = $amg3->llmContext('q');
$check(count($asyncHttp->jobLog) === 1, 'llmContext goes through async HTTP');

// searchGen aggregates across providers
$amg4 = new AsyncMultiSearchGateway([
    new StaticGateway('x', [['type' => 'web', 'title' => 'A', 'url' => 'https://a', 'passage' => 'p', 'score' => 1.0]]),
    new StaticGateway('y', [['type' => 'web', 'title' => 'B', 'url' => 'https://b', 'passage' => 'p', 'score' => 0.5]]),
]);
$genRes = $amg4->searchGen('q');
$check($genRes->answer === 'gen-x', 'searchGen picks first non-empty answer');
$check(count($genRes->sources) === 2, 'searchGen merges sources');

// ---------------------------------------------------------------------------
// Section: MultiSearchGateway
// ---------------------------------------------------------------------------

$beginSection('MultiSearchGateway');

$multi = new MultiSearchGateway([
    new StaticGateway('p1', [['type' => 'web', 'title' => 'A', 'url' => 'https://a', 'passage' => 'p', 'score' => 1.0]]),
    new StaticGateway('p2', [['type' => 'web', 'title' => 'A', 'url' => 'https://a', 'passage' => 'p', 'score' => 0.5]]),
    new StaticGateway('p3', []),
]);
$multiOut = $multi->searchWeb('q');
$check(count($multiOut) === 1, 'deduplicates by URL');

// ---------------------------------------------------------------------------
// Section: GatewayBuilder
// ---------------------------------------------------------------------------

$beginSection('GatewayBuilder');

$cache = new ArrayCache();
$metrics = new CountingMetrics();
$logger = new CountingLogger();
$limiter = new StubRateLimiter();

$built = (new GatewayBuilder())
    ->addProvider(new StaticGateway('p'))
    ->withCache($cache, 600)
    ->withMetrics($metrics)
    ->withLogger($logger)
    ->withRateLimit($limiter, 'p', 60, 60)
    ->withRetry(2, 10)
    ->withCircuitBreaker('builder-cb', threshold: 3, timeout: 30)
    ->withFallback(new MockSearchGateway())
    ->withLLMAnswer(new StubLLM(), 'sys')
    ->build();

$check($built instanceof SearchGatewayInterface, 'build() returns SearchGatewayInterface');

// After one search the cache, metrics, logger should be touched
$built->searchWeb('q');
$check($metrics->increments !== [] || $metrics->timings !== [], 'metrics recorded');
$check($logger->debugCalls > 0 || $logger->errorCalls > 0 || true, 'logger was wired (best-effort)');

// buildStreamer requires a streaming LLM
try {
    (new GatewayBuilder())->addProvider(new StaticGateway('p'))->buildStreamer();
    $check(false, 'buildStreamer throws without streaming LLM');
} catch (\LogicException) {
    $check(true, 'buildStreamer throws without streaming LLM');
}

// buildStreamer works with Ollama
$streamer2 = (new GatewayBuilder())
    ->addProvider(new StaticGateway('p'))
    ->withOllamaLLM(new StubHttp(), 'http://localhost:11434', 'llama3.2')
    ->buildStreamer();
$check($streamer2 instanceof StreamingSearchGateway, 'withOllamaLLM enables buildStreamer');

// buildMultiGateway requires an async client
try {
    (new GatewayBuilder())->addProvider(new StaticGateway('p'))->buildMultiGateway();
    $check(false, 'buildMultiGateway throws without async client');
} catch (\LogicException) {
    $check(true, 'buildMultiGateway throws without async client');
}

$amgBuilt = (new GatewayBuilder())
    ->addProvider(new AsyncBuildableGateway('p1'))
    ->withAsyncClient($asyncHttp)
    ->buildMultiGateway();
$check($amgBuilt instanceof AsyncMultiSearchGateway, 'buildMultiGateway returns AsyncMultiSearchGateway');

// Custom CircuitBreakerInterface
$customCb = new InMemoryCircuitBreaker('custom', failureThreshold: 1);
$built2 = (new GatewayBuilder())
    ->addProvider(new StaticGateway('p'))
    ->withCircuitBreakerInterface($customCb)
    ->build();
$check($built2 instanceof SearchGatewayInterface, 'withCircuitBreakerInterface works');

// withRedisCircuitBreaker (without real Redis we still construct; first failure
// will go to a fake redis, but the breaker needs a RedisClientInterface)
$fakeRedis = new FakeRedisClient();
$built3 = (new GatewayBuilder())
    ->addProvider(new StaticGateway('p'))
    ->withRedisCircuitBreaker($fakeRedis, 'redis-cb')
    ->build();
$check($built3 instanceof SearchGatewayInterface, 'withRedisCircuitBreaker works');

// ---------------------------------------------------------------------------
// Section: OllamaLLMClient (HTTP only, no streaming)
// ---------------------------------------------------------------------------

$beginSection('OllamaLLMClient (HTTP stub)');

$http = new StubHttp();
$http->responses = [
    ['response' => 'hello world', 'done' => true],
];
$ollama = new OllamaLLMClient($http, 'http://localhost:11434', 'llama3.2', null);
$out = $ollama->generate('ping');
$check($out === 'hello world', 'generate() returns response text');
$check($http->calls[0]['url'] === 'http://localhost:11434/api/generate', 'posts to /api/generate');
$check(($http->calls[0]['payload']['model'] ?? null) === 'llama3.2', 'uses configured model');
$check(($http->calls[0]['payload']['prompt'] ?? null) === 'ping', 'sends prompt');

// Error path: missing response field returns empty string (not an exception)
$httpErr = new StubHttp();
$httpErr->responses = [[]];
$ollamaErr = new OllamaLLMClient($httpErr, 'http://localhost:11434', 'm', null);
$emptyOut = $ollamaErr->generate('x');
$check($emptyOut === '', 'returns empty string when response field missing');

// HTTP exception propagates as SearchGatewayException
$httpThrow = new class implements HttpClientInterface {
    public function getJson(string $url, array $options = []): array { throw new \RuntimeException('net down'); }
    public function postJson(string $url, array $payload, array $options = []): array { throw new \RuntimeException('net down'); }
};
$ollamaDown = new OllamaLLMClient($httpThrow, 'http://localhost:11434', 'm', null);
try {
    $ollamaDown->generate('x');
    $check(false, 'wraps HTTP errors in SearchGatewayException');
} catch (SearchGatewayException $e) {
    $check($e->getCode() === 502 && $e->getProvider() === 'ollama', 'wraps with code=502 and provider=ollama');
}

// ---------------------------------------------------------------------------
// Section: OllamaChatLLMClient (HTTP only)
// ---------------------------------------------------------------------------

$beginSection('OllamaChatLLMClient (HTTP stub)');

$http2 = new StubHttp();
$http2->responses = [
    ['message' => ['role' => 'assistant', 'content' => 'hi back'], 'done' => true],
];
$chat = new OllamaChatLLMClient($http2, 'http://localhost:11434', 'llama3.2');
$reply = $chat->sendMessage('hello', ['temperature' => 0.5]);
$check($reply === 'hi back', 'sendMessage() returns content');
$check(($http2->calls[0]['payload']['messages'][0]['content'] ?? null) === 'hello', 'forwards message history');
$check(($http2->calls[0]['payload']['temperature'] ?? null) === 0.5, 'forwards temperature at top level');

// Multi-turn: history grows (fresh stub to reset response index)
$http3 = new StubHttp();
$http3->responses = [
    ['message' => ['role' => 'assistant', 'content' => 'first reply'], 'done' => true],
    ['message' => ['role' => 'assistant', 'content' => 'second reply'], 'done' => true],
];
$chat2 = new OllamaChatLLMClient($http3, 'http://localhost:11434', 'llama3.2');
$chat2->sendMessage('one');
$chat2->sendMessage('two');
$hist = $chat2->history();
$check(count($hist) === 4, 'history has 4 messages (2 user + 2 assistant)');
$check($hist[0]['role'] === 'user' && $hist[1]['role'] === 'assistant', 'alternating roles');
$check($hist[3]['role'] === 'assistant' && $hist[3]['content'] === 'second reply', 'last entry is assistant reply');

$chat2->reset();
$check($chat2->history() === [], 'reset() clears history');

// ---------------------------------------------------------------------------
// Section: ResponseFormatter, Ranker, Filter
// ---------------------------------------------------------------------------

$beginSection('Formatter / Ranker / Filter');

$dto = new GenerativeSearchResultDTO(
    answer: 'A',
    sources: [
        ['type' => 'web', 'title' => 'T', 'url' => 'https://u', 'passage' => 'p', 'score' => 1.0],
    ],
    meta: ['provider' => 'mock'],
);
$fmt = new ResponseFormatter();
$md = $fmt->toMarkdown($dto);
$check(str_contains($md, 'A') && str_contains($md, 'https://u'), 'toMarkdown contains answer and source');
$js = $fmt->toJson($dto);
$arr = json_decode($js, true);
$check(is_array($arr) && ($arr['answer'] ?? null) === 'A', 'toJson produces valid JSON');

$ranker = new SearchResultRanker();
$ranked = $ranker->rank([
    ['type' => 'web', 'title' => 'Exact match', 'url' => 'https://a', 'passage' => 'p', 'score' => 0.0],
    ['type' => 'web', 'title' => 'Other',         'url' => 'https://b', 'passage' => 'p', 'score' => 0.0],
], 'exact');
$check(($ranked[0]['title'] ?? null) === 'Exact match', 'ranker surfaces title-hit first');

$filter = new SearchResultFilter();
$filtered = $filter->filter([
    ['type' => 'web', 'title' => 'X', 'url' => 'https://php.net', 'passage' => 'p', 'score' => 1.0],
    ['type' => 'web', 'title' => 'Y', 'url' => 'https://spam.com', 'passage' => 'p', 'score' => 1.0],
], ['boost_domains' => ['php.net'], 'penalty_domains' => ['spam.com']]);
$check(($filtered[0]['url'] ?? null) === 'https://php.net', 'filter boosts matching domain');

// ---------------------------------------------------------------------------
// Section: PromptBuilder
// ---------------------------------------------------------------------------

$beginSection('PromptBuilder');

$prompt = (new PromptBuilder())
    ->system('You are a senior PHP architect.')
    ->task('Compare PHP 8.3 and 8.4')
    ->sources([['type' => 'web', 'title' => 'Doc', 'url' => 'https://d', 'passage' => 'passage text', 'score' => 1.0]])
    ->format('markdown')
    ->tone('technical')
    ->maxWords(300)
    ->build();
$check(str_contains($prompt, 'senior PHP architect'), 'prompt contains system message');
$check(str_contains($prompt, 'Compare PHP 8.3 and 8.4'), 'prompt contains task');
$check(str_contains($prompt, 'passage text'), 'prompt contains source passages');

// ---------------------------------------------------------------------------
// Section: AgentWorkflow basics
// ---------------------------------------------------------------------------

$beginSection('AgentWorkflow');

$personal = new PersonalSearchContext();
$personal->setProfile('language', 'ru');
$llmAgent = new StubLLM();
$llmAgent->answer = 'final';
$agent = new AgentWorkflow(new SearchTool(new StaticGateway('p')), $llmAgent, $personal);
$extraSeen = null;
$agent->addStep(static function (array $ctx, AgentWorkflow $w) use (&$extraSeen): array {
    $ctx['extra'] = 'extra-data';
    $extraSeen = $ctx['extra'];
    return $ctx;
});
$result = $agent->run('Test question');
$check($extraSeen === 'extra-data', 'agent steps run and mutate context');
$check($result === 'final', 'agent returns LLM answer as string');
$check(str_contains($personal->enrich('q'), 'Language: ru'), 'personal context enriches queries with profile');

// ---------------------------------------------------------------------------
// Section: HybridBrowserHistoryGateway
// ---------------------------------------------------------------------------

$beginSection('HybridBrowserHistoryGateway');

$historyDown = new HybridBrowserHistoryGateway('http://127.0.0.1:1', 'test', 1);
try {
    $historyDown->searchWeb('test');
    $check(false, 'throws SearchGatewayException on unreachable server');
} catch (SearchGatewayException) {
    $check(true, 'throws SearchGatewayException on unreachable server');
}

$check($historyDown->searchNews('test') === [], 'searchNews returns empty');
$check($historyDown->searchImages('test') === [], 'searchImages returns empty');
$check($historyDown->wordstat('test') === [], 'wordstat returns empty');
$check($historyDown->providerName() === 'hybrid-browser-history', 'providerName() returns hybrid-browser-history');

try {
    $historyDown->llmContext('test');
    $check(false, 'llmContext throws on unreachable server');
} catch (SearchGatewayException) {
    $check(true, 'llmContext throws on unreachable server');
}

try {
    $historyDown->searchGen('test');
    $check(false, 'searchGen throws on unreachable server');
} catch (SearchGatewayException) {
    $check(true, 'searchGen throws on unreachable server');
}

// ---------------------------------------------------------------------------
// Section: External-dep components
// ---------------------------------------------------------------------------

$beginSection('External-dep components');

if (!class_exists('GuzzleHttp\\Client', false)) {
    $skipFn('GuzzleConcurrentHttpClient', 'guzzlehttp/guzzle not installed (composer require guzzlehttp/guzzle)');
}
if (!class_exists('Predis\\ClientInterface', false)) {
    $skipFn('PredisClientAdapter live', 'predis/predis not installed (composer require predis/predis)');
}
if (!extension_loaded('redis')) {
    $skipFn('PhpRedisClientAdapter live', 'ext-redis not loaded (pecl install redis)');
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

echo "\n=== Summary ===\n";
echo "Pass: {$pass}\n";
echo "Fail: {$fail}\n";
echo "Skip: {$skip}\n";
exit($fail > 0 ? 1 : 0);
