<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Gateway;

use PHPUnit\Framework\TestCase;
use SearchGateway\Gateway\HybridBrowserHistoryGateway;
use SearchGateway\Contract\SearchGatewayException;

final class HybridBrowserHistoryGatewayTest extends TestCase
{
    public function testSearchWebThrowsExceptionWhenServerUnreachable(): void
    {
        $gateway = new HybridBrowserHistoryGateway('http://127.0.0.1:1', 'test-token', 1);
        $this->expectException(SearchGatewayException::class);
        $gateway->searchWeb('test query');
    }

    public function testSearchGenThrowsExceptionWhenServerUnreachable(): void
    {
        $gateway = new HybridBrowserHistoryGateway('http://127.0.0.1:1', 'test-token', 1);
        $this->expectException(SearchGatewayException::class);
        $gateway->searchGen('test query');
    }

    public function testLlmContextThrowsExceptionWhenServerUnreachable(): void
    {
        $gateway = new HybridBrowserHistoryGateway('http://127.0.0.1:1', 'test-token', 1);
        $this->expectException(SearchGatewayException::class);
        $gateway->llmContext('test query');
    }

    public function testSearchNewsReturnsEmptyArray(): void
    {
        $gateway = new HybridBrowserHistoryGateway();
        $result = $gateway->searchNews('test');
        $this->assertSame([], $result);
    }

    public function testSearchImagesReturnsEmptyArray(): void
    {
        $gateway = new HybridBrowserHistoryGateway();
        $result = $gateway->searchImages('test');
        $this->assertSame([], $result);
    }

    public function testWordstatReturnsEmptyArray(): void
    {
        $gateway = new HybridBrowserHistoryGateway();
        $result = $gateway->wordstat('test');
        $this->assertSame([], $result);
    }

    public function testProviderName(): void
    {
        $gateway = new HybridBrowserHistoryGateway();
        $this->assertSame('hybrid-browser-history', $gateway->providerName());
    }
}
