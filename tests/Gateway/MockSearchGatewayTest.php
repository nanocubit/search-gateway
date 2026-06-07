<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Gateway;

use PHPUnit\Framework\TestCase;
use SearchGateway\Gateway\MockSearchGateway;

final class MockSearchGatewayTest extends TestCase
{
    public function testReturnsDefaultMockResults(): void
    {
        $mock = new MockSearchGateway();
        $res = $mock->searchWeb('php');

        $this->assertCount(1, $res);
        $titleRaw = $res[0]['title'];
        $title = is_scalar($titleRaw) ? (string) $titleRaw : '';
        $this->assertSame('web', $res[0]['type']);
        $this->assertStringContainsString('php', $title);
    }

    public function testReturnsConfiguredResponses(): void
    {
        $mock = new MockSearchGateway([
            'searchWeb' => [
                ['type' => 'web', 'title' => 'Custom', 'url' => 'https://custom.com', 'passage' => 'P', 'score' => 0.99],
            ],
        ]);

        $res = $mock->searchWeb('anything');
        $this->assertSame('Custom', $res[0]['title']);
    }

    public function testLlmContextNormalisesMockDocs(): void
    {
        $mock = new MockSearchGateway();
        $ctx = $mock->llmContext('test');

        $this->assertArrayHasKey('domain', $ctx[0]);
        $this->assertSame('mock.example.com', $ctx[0]['domain']);
    }
}
