<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Builder;

use PHPUnit\Framework\TestCase;
use SearchGateway\Builder\GatewayBuilder;
use SearchGateway\Contract\CircuitBreakerInterface;
use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Gateway\MockSearchGateway;
use SearchGateway\Infrastructure\CacheInterface;
use SearchGateway\Resilience\InMemoryCircuitBreaker;
use SearchGateway\Resilience\RedisCircuitBreaker;
use SearchGateway\Tests\Resilience\FakeRedisClient;

final class GatewayBuilderTest extends TestCase
{
    public function testBuildsSingleProvider(): void
    {
        $gateway = (new GatewayBuilder())
            ->addYandex(new \stdClass())
            ->build();

        $this->assertInstanceOf(SearchGatewayInterface::class, $gateway);
    }

    public function testBuildsWithCacheAndRetry(): void
    {
        $cache = $this->makeInMemoryCache();

        $gateway = (new GatewayBuilder())
            ->addYandex(new \stdClass())
            ->withCache($cache, 120)
            ->withRetry(3, 100)
            ->build();

        $this->assertInstanceOf(SearchGatewayInterface::class, $gateway);
        $mock = new MockSearchGateway();
        $cached = new \SearchGateway\Decorator\CachedSearchGatewayDecorator($mock, $cache, 120);
        $r1 = $cached->searchWeb('q');
        $r2 = $cached->searchWeb('q');
        $this->assertEquals($r1, $r2);
    }

    public function testWithCircuitBreakerInterface(): void
    {
        $cb = new InMemoryCircuitBreaker('test', failureThreshold: 5);

        $gateway = (new GatewayBuilder())
            ->addYandex(new \stdClass())
            ->withCircuitBreakerInterface($cb)
            ->build();

        $this->assertInstanceOf(SearchGatewayInterface::class, $gateway);
    }

    public function testWithRedisCircuitBreaker(): void
    {
        $redis = new FakeRedisClient();

        $gateway = (new GatewayBuilder())
            ->addYandex(new \stdClass())
            ->withRedisCircuitBreaker($redis, 'svc', threshold: 3)
            ->build();

        $this->assertInstanceOf(SearchGatewayInterface::class, $gateway);
    }

    public function testWithCircuitBreakerBuildsInMemoryBreaker(): void
    {
        $gateway = (new GatewayBuilder())
            ->addYandex(new \stdClass())
            ->withCircuitBreaker('test', 5, 30)
            ->build();

        $this->assertInstanceOf(SearchGatewayInterface::class, $gateway);
    }

    public function testBuildMultiGatewayRequiresAsyncClient(): void
    {
        $this->expectException(\LogicException::class);
        (new GatewayBuilder())
            ->addYandex(new \stdClass())
            ->buildMultiGateway();
    }

    public function testBuildStreamerRequiresStreamingLlm(): void
    {
        $this->expectException(\LogicException::class);
        (new GatewayBuilder())
            ->addYandex(new \stdClass())
            ->buildStreamer();
    }

    private function makeInMemoryCache(): CacheInterface
    {
        return new class implements CacheInterface {
            /** @var array<string, mixed> */
            private array $store = [];
            public function get(string $key): mixed
            {
                return $this->store[$key] ?? null;
            }
            public function set(string $key, mixed $value, int $ttlSeconds): bool
            {
                $this->store[$key] = $value;
                return true;
            }
        };
    }
}
