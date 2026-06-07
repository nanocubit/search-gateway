<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Decorator;

use PHPUnit\Framework\TestCase;
use SearchGateway\Contract\CircuitBreakerInterface;
use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Decorator\CircuitBreakerSearchGatewayDecorator;
use SearchGateway\Resilience\CircuitBreaker;
use SearchGateway\Resilience\CircuitOpenException;
use SearchGateway\Resilience\InMemoryCircuitBreaker;
use SearchGateway\Resilience\RedisCircuitBreaker;
use SearchGateway\Tests\Resilience\FakeRedisClient;

final class CircuitBreakerSearchGatewayDecoratorTest extends TestCase
{
    public function testProtectsInnerGateway(): void
    {
        $inner = $this->createMock(SearchGatewayInterface::class);
        $inner->expects($this->exactly(3))
            ->method('searchWeb')
            ->willReturnOnConsecutiveCalls(
                [['title' => 'A']],
                $this->throwException(new \RuntimeException('timeout')),
                $this->throwException(new \RuntimeException('timeout'))
            );

        $cb = new CircuitBreaker('test', failureThreshold: 2, recoveryTimeoutSeconds: 3600);
        $decorator = new CircuitBreakerSearchGatewayDecorator($inner, $cb);

        $this->assertSame('A', $decorator->searchWeb('q')[0]['title']);

        try {
            $decorator->searchWeb('q');
        } catch (\RuntimeException) {
        }

        try {
            $decorator->searchWeb('q');
        } catch (\RuntimeException) {
        }

        $this->assertSame(CircuitBreaker::STATE_OPEN, $decorator->getBreaker()->getState());

        $this->expectException(CircuitOpenException::class);
        $decorator->searchWeb('q');
    }

    public function testAcceptsAnyCircuitBreakerInterface(): void
    {
        $inner = $this->createMock(SearchGatewayInterface::class);
        $inner->method('searchWeb')->willReturn([['title' => 'A']]);

        $cb = new InMemoryCircuitBreaker('test', failureThreshold: 1);
        $decorator = new CircuitBreakerSearchGatewayDecorator($inner, $cb);

        $this->assertInstanceOf(CircuitBreakerInterface::class, $decorator->getBreaker());
        $this->assertSame('A', $decorator->searchWeb('q')[0]['title']);
    }

    public function testWorksWithRedisCircuitBreaker(): void
    {
        $inner = $this->createMock(SearchGatewayInterface::class);
        $inner->method('searchWeb')->willReturn([['title' => 'A']]);

        $cb = new RedisCircuitBreaker(new FakeRedisClient(), 'test', failureThreshold: 1);
        $decorator = new CircuitBreakerSearchGatewayDecorator($inner, $cb);

        $this->assertSame('A', $decorator->searchWeb('q')[0]['title']);
    }
}
