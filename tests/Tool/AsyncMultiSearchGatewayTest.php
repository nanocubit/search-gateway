<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Tool;

use PHPUnit\Framework\TestCase;
use SearchGateway\Contract\AsyncHttpClientInterface;
use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Gateway\AbstractSearchGateway;
use SearchGateway\Tool\AsyncMultiSearchGateway;
use SearchGateway\Tool\AsyncRequestBuilderInterface;

final class AsyncMultiSearchGatewayTest extends TestCase
{
    public function testFallsBackToSequentialWhenNoBuilder(): void
    {
        $p1 = $this->createMock(SearchGatewayInterface::class);
        $p1->method('llmContext')->willReturn([
            ['url' => 'https://a.com', 'title' => 'A', 'passage' => 'p', 'score' => 0.9],
        ]);
        $p2 = $this->createMock(SearchGatewayInterface::class);
        $p2->method('llmContext')->willReturn([
            ['url' => 'https://b.com', 'title' => 'B', 'passage' => 'p', 'score' => 0.7],
        ]);

        $multi = new AsyncMultiSearchGateway([$p1, $p2], $this->createMock(AsyncHttpClientInterface::class));
        $ctx = $multi->llmContext('q');

        $urls = array_column($ctx, 'url');
        $this->assertContains('https://a.com', $urls);
        $this->assertContains('https://b.com', $urls);
    }

    public function testUsesAsyncClientForBuilders(): void
    {
        $builder = new class extends AbstractSearchGateway implements AsyncRequestBuilderInterface {
            /**
             * @param array<string, mixed> $options
             */
            public function searchWeb(string $query, array $options = []): array
            {
                return [];
            }
            /**
             * @param array<string, mixed> $options
             */
            public function searchNews(string $query, array $options = []): array
            {
                return [];
            }
            /**
             * @param array<string, mixed> $options
             */
            public function searchImages(string $query, array $options = []): array
            {
                return [];
            }
            /**
             * @param array<string, mixed> $options
             */
            public function searchGen(string $query, array $options = []): GenerativeSearchResultDTO
            {
                return GenerativeSearchResultDTO::empty('test');
            }
            /**
             * @param array<string, mixed> $options
             */
            public function wordstat(string $query, array $options = []): array
            {
                return [];
            }
            /**
             * @param array<string, mixed> $options
             */
            public function llmContext(string $query, array $options = []): array
            {
                return [];
            }
            public function providerName(): string
            {
                return 'test-builder';
            }
            /**
             * @param array<string, mixed> $options
             * @return list<array<string, mixed>>
             */
            public function buildRequests(string $method, string $query, array $options): array
            {
                return [
                    [
                        'method' => 'GET',
                        'uri' => 'https://example.com/?q=' . urlencode($query),
                        'headers' => ['Accept' => 'application/json'],
                        'body' => null,
                        'provider' => 'test-builder',
                        'decode' => static fn(string $raw): array => [
                            ['url' => 'https://x.com', 'title' => 'X', 'passage' => 'P', 'score' => 0.5],
                        ],
                    ],
                ];
            }
        };

        $async = new class implements AsyncHttpClientInterface {
            /** @var array<string, array<string, mixed>> */
            public array $capturedJobs = [];
            public function runConcurrent(array $jobs, array $options = []): array
            {
                $this->capturedJobs = $jobs;
                $results = [];
                foreach ($jobs as $key => $job) {
                    $uriRaw = $job['uri'] ?? '';
                    $uri = is_string($uriRaw) ? $uriRaw : '';
                    $decoder = $job['decode'] ?? null;
                    $value = is_callable($decoder) ? $decoder($uri) : [];
                    $providerRaw = $job['provider'] ?? null;
                    $results[$key] = [
                        'success' => true,
                        'value' => $value,
                        'error' => null,
                        'provider' => is_string($providerRaw) ? $providerRaw : null,
                        'latency_ms' => 0.0,
                        'status' => 200,
                    ];
                }
                return $results;
            }
        };

        $multi = new AsyncMultiSearchGateway([$builder], $async);
        $ctx = $multi->llmContext('hello');

        $this->assertCount(1, $async->capturedJobs);
        $this->assertCount(1, $ctx);
        $this->assertSame('https://x.com', $ctx[0]['url']);
    }

    public function testSearchGenPicksFirstAnswer(): void
    {
        $p1 = $this->createMock(SearchGatewayInterface::class);
        $p1->method('searchGen')->willReturn(new GenerativeSearchResultDTO(
            answer: 'A1',
            sources: [],
            meta: ['provider' => 'p1']
        ));
        $p2 = $this->createMock(SearchGatewayInterface::class);
        $p2->method('searchGen')->willReturn(new GenerativeSearchResultDTO(
            answer: '',
            sources: [],
            meta: ['provider' => 'p2']
        ));

        $multi = new AsyncMultiSearchGateway([$p1, $p2]);
        $gen = $multi->searchGen('q');

        $this->assertSame('A1', $gen->answer);
    }
}
