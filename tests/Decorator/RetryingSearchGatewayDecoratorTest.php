<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Decorator;

use PHPUnit\Framework\TestCase;
use SearchGateway\Contract\SearchGatewayException;
use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Decorator\RetryingSearchGatewayDecorator;

final class RetryingSearchGatewayDecoratorTest extends TestCase
{
    public function testRetriesThenSucceeds(): void
    {
        $inner = $this->createMock(SearchGatewayInterface::class);
        $inner->expects($this->exactly(2))
            ->method('searchWeb')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new \RuntimeException('timeout')),
                [['title' => 'OK']]
            );

        $decorator = new RetryingSearchGatewayDecorator($inner, retries: 2, delayMs: 10);
        $result = $decorator->searchWeb('q');

        $this->assertSame('OK', $result[0]['title']);
    }

    public function testThrowsAfterExhaustedRetries(): void
    {
        $inner = $this->createMock(SearchGatewayInterface::class);
        $inner->expects($this->exactly(3))
            ->method('searchWeb')
            ->willThrowException(new \RuntimeException('fail'));

        $decorator = new RetryingSearchGatewayDecorator($inner, retries: 2, delayMs: 10);

        $this->expectException(SearchGatewayException::class);
        $decorator->searchWeb('q');
    }
}
