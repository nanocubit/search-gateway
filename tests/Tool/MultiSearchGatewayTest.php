<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Tool;

use PHPUnit\Framework\TestCase;
use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Tool\MultiSearchGateway;

final class MultiSearchGatewayTest extends TestCase
{
    public function testLlmContextMergesAndDedupes(): void
    {
        $p1 = $this->createMock(SearchGatewayInterface::class);
        $p1->method('llmContext')->willReturn([
            ['url' => 'https://a.com', 'title' => 'A', 'passage' => 'pa', 'score' => 0.9],
            ['url' => 'https://b.com', 'title' => 'B', 'passage' => 'pb', 'score' => 0.8],
        ]);

        $p2 = $this->createMock(SearchGatewayInterface::class);
        $p2->method('llmContext')->willReturn([
            ['url' => 'https://a.com', 'title' => 'A2', 'passage' => 'pa2', 'score' => 0.95],
            ['url' => 'https://c.com', 'title' => 'C', 'passage' => 'pc', 'score' => 0.7],
        ]);

        $multi = new MultiSearchGateway([$p1, $p2]);
        $ctx = $multi->llmContext('q', ['docsOnPage' => 10]);

        $urls = array_column($ctx, 'url');
        $this->assertCount(3, $urls);
        $this->assertContains('https://a.com', $urls);
        $this->assertContains('https://b.com', $urls);
        $this->assertContains('https://c.com', $urls);

        // A should have higher score (0.95) because dedup keeps best score
        $a = array_values(array_filter($ctx, fn(array $d): bool => $d['url'] === 'https://a.com'))[0];
        $this->assertSame(0.95, $a['score']);
    }

    public function testSearchGenPicksFirstAnswer(): void
    {
        $p1 = $this->createMock(SearchGatewayInterface::class);
        $p1->method('searchGen')->willReturn(new GenerativeSearchResultDTO(
            answer: '',
            sources: [['url' => 'https://x.com']],
            meta: ['provider' => 'p1']
        ));

        $p2 = $this->createMock(SearchGatewayInterface::class);
        $p2->method('searchGen')->willReturn(new GenerativeSearchResultDTO(
            answer: 'Answer from p2',
            sources: [['url' => 'https://y.com']],
            meta: ['provider' => 'p2']
        ));

        $multi = new MultiSearchGateway([$p1, $p2]);
        $gen = $multi->searchGen('q');

        $this->assertSame('Answer from p2', $gen->answer);
        $this->assertCount(2, $gen->sources);
    }
}
