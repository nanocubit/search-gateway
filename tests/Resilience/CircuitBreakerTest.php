<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Resilience;

use PHPUnit\Framework\TestCase;
use SearchGateway\Resilience\CircuitBreaker;

final class CircuitBreakerTest extends TestCase
{
    public function testClosesAfterThresholdFailures(): void
    {
        $cb = new CircuitBreaker('test', failureThreshold: 3, recoveryTimeoutSeconds: 10);

        // 3 failures -> OPEN
        for ($i = 0; $i < 3; $i++) {
            try {
                $cb->call(fn() => throw new \RuntimeException('fail'));
            } catch (\RuntimeException) {
                // expected
            }
        }

        $this->assertSame(CircuitBreaker::STATE_OPEN, $cb->getState());

        // Next call must fail immediately
        $this->expectException(\RuntimeException::class);
        $cb->call(fn() => 'success');
    }

    public function testHalfOpenThenCloses(): void
    {
        $cb = new CircuitBreaker('test', failureThreshold: 1, recoveryTimeoutSeconds: 0, halfOpenMaxCalls: 2);

        // Trigger open
        try {
            $cb->call(fn() => throw new \RuntimeException('fail'));
        } catch (\RuntimeException) {
        }

        $this->assertSame(CircuitBreaker::STATE_OPEN, $cb->getState());

        // Immediate retry because recoveryTimeout=0 -> half-open
        $cb->call(fn() => 'ok1');
        $this->assertSame(CircuitBreaker::STATE_HALF_OPEN, $cb->getState());

        $cb->call(fn() => 'ok2');
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $cb->getState());
    }
}
