<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Resilience;

use PHPUnit\Framework\TestCase;
use SearchGateway\Resilience\CircuitOpenException;
use SearchGateway\Resilience\InMemoryCircuitBreaker;

final class InMemoryCircuitBreakerTest extends TestCase
{
    public function testOpensAfterThresholdFailures(): void
    {
        $cb = new InMemoryCircuitBreaker('test', failureThreshold: 3, recoveryTimeoutSeconds: 10);

        for ($i = 0; $i < 3; $i++) {
            try {
                $cb->call(static fn() => throw new \RuntimeException('fail'));
            } catch (\RuntimeException) {
                // expected
            }
        }

        $this->assertSame(InMemoryCircuitBreaker::STATE_OPEN, $cb->getState());

        $this->expectException(CircuitOpenException::class);
        $cb->call(static fn() => 'success');
    }

    public function testHalfOpenThenCloses(): void
    {
        $cb = new InMemoryCircuitBreaker(
            'test',
            failureThreshold: 1,
            recoveryTimeoutSeconds: 0,
            halfOpenMaxCalls: 2,
        );

        try {
            $cb->call(static fn() => throw new \RuntimeException('fail'));
        } catch (\RuntimeException) {
        }

        $this->assertSame(InMemoryCircuitBreaker::STATE_OPEN, $cb->getState());

        $cb->call(static fn() => 'ok1');
        $this->assertSame(InMemoryCircuitBreaker::STATE_HALF_OPEN, $cb->getState());

        $cb->call(static fn() => 'ok2');
        $this->assertSame(InMemoryCircuitBreaker::STATE_CLOSED, $cb->getState());
    }

    public function testResetCloses(): void
    {
        $cb = new InMemoryCircuitBreaker('test', failureThreshold: 1);
        try {
            $cb->call(static fn() => throw new \RuntimeException('fail'));
        } catch (\RuntimeException) {
        }
        $this->assertSame(InMemoryCircuitBreaker::STATE_OPEN, $cb->getState());

        $cb->reset();
        $this->assertSame(InMemoryCircuitBreaker::STATE_CLOSED, $cb->getState());
    }

    public function testHalfOpenClosesAfterMaxProbes(): void
    {
        $cb = new InMemoryCircuitBreaker(
            'test',
            failureThreshold: 1,
            recoveryTimeoutSeconds: 0,
            halfOpenMaxCalls: 2,
        );

        try {
            $cb->call(static fn() => throw new \RuntimeException('fail'));
        } catch (\RuntimeException) {
        }

        $cb->call(static fn() => 'a');
        $cb->call(static fn() => 'b');
        $this->assertSame(InMemoryCircuitBreaker::STATE_CLOSED, $cb->getState());
    }

    public function testBCAliasResolves(): void
    {
        $alias = new \SearchGateway\Resilience\CircuitBreaker('alias', failureThreshold: 1);
        $this->assertInstanceOf(InMemoryCircuitBreaker::class, $alias);
    }
}
