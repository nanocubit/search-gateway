<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Ranker;

use PHPUnit\Framework\TestCase;
use SearchGateway\Ranker\SearchResultRanker;

final class SearchResultRankerTest extends TestCase
{
    public function testBoostsDomainAndTermDensity(): void
    {
        $ranker = new SearchResultRanker();
        $docs = [
            ['url' => 'https://bad.com/a', 'title' => 'A', 'passage' => 'some text', 'score' => 0.8],
            ['url' => 'https://good.com/b', 'title' => 'B', 'passage' => 'php 8.4 features and php 8.4 benchmarks', 'score' => 0.7],
            ['url' => 'https://neutral.com/c', 'title' => 'C', 'passage' => 'other', 'score' => 0.9],
        ];

        $ranked = $ranker->rank($docs, 'php 8.4 features', [
            'boost_domains' => ['good.com'],
            'penalty_domains' => ['bad.com'],
        ]);

        // good.com should jump ahead despite lower initial score due to domain boost + term density
        $this->assertSame('https://good.com/b', $ranked[0]['url']);
        // neutral.com stays second
        $this->assertSame('https://neutral.com/c', $ranked[1]['url']);
    }
}
