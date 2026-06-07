<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Retrieval;

use PHPUnit\Framework\TestCase;
use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Embedding\EmbeddingInterface;
use SearchGateway\Gateway\MockSearchGateway;
use SearchGateway\Retrieval\AdaptiveRetriever;
use SearchGateway\Tests\Support\InMemoryVectorStore;
use SearchGateway\Tests\Support\MockLLMClient;

final class AdaptiveRetrieverTest extends TestCase
{
    public function testRoutesWebQueryToWebSearch(): void
    {
        $web = new MockSearchGateway([
            'llmContext' => [
                [
                    'url' => 'https://news.example.com/x',
                    'title' => 'Latest news on PHP',
                    'domain' => 'news.example.com',
                    'passage' => 'news text',
                    'score' => 0.9,
                ],
            ],
        ]);
        $vec = new InMemoryVectorStore();
        $llm = new MockLLMClient(['web']);

        $retriever = new AdaptiveRetriever($web, $vec, $llm, $this->embedding());
        $result = $retriever->retrieve('Latest news about PHP 8.4 in 2026');

        $this->assertSame('web', $result['strategy']);
        $this->assertSame('web', $result['intent']);
        $this->assertCount(1, $result['results']);
        $this->assertSame('https://news.example.com/x', $result['results'][0]['url']);
        $this->assertSame(0, $vec->searchCalls);
    }

    public function testRoutesVectorQueryToVectorStoreWhenEmbeddingProvided(): void
    {
        $web = new MockSearchGateway();
        $vec = new InMemoryVectorStore([
            [
                'id' => 'doc-1',
                'vector' => [0.1, 0.2, 0.3],
                'meta' => [
                    'url' => 'kb://internal/policy',
                    'title' => 'Internal policy',
                    'passage' => 'handbook excerpt',
                    'score' => 0.8,
                ],
            ],
        ]);
        $llm = new MockLLMClient();

        $retriever = new AdaptiveRetriever($web, $vec, $llm, $this->embedding());
        $result = $retriever->retrieve('our company internal policy');

        $this->assertSame('vector', $result['strategy']);
        $this->assertSame('vector', $result['intent']);
        $this->assertCount(1, $result['results']);
        $this->assertSame('kb://internal/policy', $result['results'][0]['url']);
        $this->assertSame(1, $vec->searchCalls);
        $this->assertSame(5, $vec->lastK);
    }

    public function testRoutesHybridQueryToBothSourcesAndDeduplicates(): void
    {
        $web = new MockSearchGateway([
            'llmContext' => [
                [
                    'url' => 'https://compare.example.com/a',
                    'title' => 'Compare A',
                    'domain' => 'compare.example.com',
                    'passage' => 'a passage',
                    'score' => 0.7,
                ],
                [
                    'url' => 'https://shared.example.com/dup',
                    'title' => 'Shared',
                    'domain' => 'shared.example.com',
                    'passage' => 'dup',
                    'score' => 0.6,
                ],
            ],
        ]);
        $vec = new InMemoryVectorStore([
            [
                'id' => 'v1',
                'vector' => [0.5],
                'meta' => [
                    'url' => 'https://shared.example.com/dup',
                    'title' => 'Shared (from vector)',
                    'passage' => 'dup from vector',
                    'score' => 0.55,
                ],
            ],
            [
                'id' => 'v2',
                'vector' => [0.6],
                'meta' => [
                    'url' => 'https://vec.example.com/b',
                    'title' => 'B',
                    'passage' => 'b passage',
                    'score' => 0.4,
                ],
            ],
        ]);
        $llm = new MockLLMClient();

        $retriever = new AdaptiveRetriever($web, $vec, $llm, $this->embedding());
        $result = $retriever->retrieve('compare PHP vs Python benchmark');

        $this->assertSame('hybrid', $result['strategy']);
        $this->assertSame('hybrid', $result['intent']);

        $urls = array_map(
            static function (array $d): string {
                $u = $d['url'] ?? '';
                return is_scalar($u) ? (string) $u : '';
            },
            $result['results']
        );
        $this->assertCount(3, $urls, 'duplicate URL should be removed');
        $this->assertSame(
            ['https://compare.example.com/a', 'https://shared.example.com/dup', 'https://vec.example.com/b'],
            $urls
        );
    }

    public function testFallsBackToWebWhenEmbeddingMissing(): void
    {
        $web = new MockSearchGateway();
        $vec = new InMemoryVectorStore();
        $llm = new MockLLMClient();

        $retriever = new AdaptiveRetriever($web, $vec, $llm, null);
        $result = $retriever->retrieve('our internal policy document');

        // vector intent is detected, but no embedding -> degrade to web
        $this->assertSame('web', $result['strategy']);
        $this->assertSame('vector', $result['intent']);
        $this->assertSame(0, $vec->searchCalls);
    }

    public function testAmbiguousQueryUsesLlmClassification(): void
    {
        $web = new MockSearchGateway();
        $vec = new InMemoryVectorStore();
        $llm = new MockLLMClient(['hybrid']);

        $retriever = new AdaptiveRetriever($web, $vec, $llm, $this->embedding());
        $result = $retriever->retrieve('something random xyz 12345');

        $this->assertSame('hybrid', $result['strategy']);
        $this->assertSame('ambiguous', $result['intent']);
        $this->assertCount(1, $llm->seenPrompts(), 'LLM should be invoked for ambiguous queries');
    }

    public function testInvalidLlmClassificationFallsBackToWeb(): void
    {
        $web = new MockSearchGateway();
        $vec = new InMemoryVectorStore();
        $llm = new MockLLMClient(['gibberish-response']);

        $retriever = new AdaptiveRetriever($web, $vec, $llm, $this->embedding());
        $result = $retriever->retrieve('something random xyz 12345');

        $this->assertSame('web', $result['strategy']);
    }

    public function testRussianKeywordsAlsoClassify(): void
    {
        $web = new MockSearchGateway();
        $vec = new InMemoryVectorStore();
        $llm = new MockLLMClient();

        $retriever = new AdaptiveRetriever($web, $vec, $llm, null);
        $result = $retriever->retrieve('последние новости о PHP 8.4');

        $this->assertSame('web', $result['strategy']);
        $this->assertSame('web', $result['intent']);
    }

    public function testHonoursKOption(): void
    {
        $web = new MockSearchGateway();
        $vec = new InMemoryVectorStore();
        $llm = new MockLLMClient();

        $retriever = new AdaptiveRetriever($web, $vec, $llm, $this->embedding());
        $retriever->retrieve('our internal policy', ['k' => 7]);

        $this->assertSame(7, $vec->lastK);
    }

    public function testInvalidKFallsBackToFive(): void
    {
        $web = new MockSearchGateway();
        $vec = new InMemoryVectorStore();
        $llm = new MockLLMClient();

        $retriever = new AdaptiveRetriever($web, $vec, $llm, $this->embedding());
        $retriever->retrieve('our internal policy', ['k' => 'not-an-int']);

        $this->assertSame(5, $vec->lastK);
    }

    public function testMockSearchGatewayWorksAsWebSource(): void
    {
        $gateway = new MockSearchGateway([
            'llmContext' => [
                [
                    'url' => 'https://mock.example.com/web/x',
                    'title' => 'mock title',
                    'domain' => 'mock.example.com',
                    'passage' => 'mock passage',
                    'score' => 1.0,
                ],
            ],
        ]);
        $llm = new MockLLMClient();
        $retriever = new AdaptiveRetriever($gateway, new InMemoryVectorStore(), $llm, null);
        $result = $retriever->retrieve('latest news today');
        $this->assertSame('web', $result['strategy']);
        $this->assertSame('mock.example.com', $result['results'][0]['domain']);
    }

    private function embedding(): EmbeddingInterface
    {
        return new class implements EmbeddingInterface {
            public function embed(string $text): array
            {
                return [0.1, 0.2, 0.3];
            }

            public function embedBatch(array $texts): array
            {
                $out = [];
                foreach ($texts as $t) {
                    $out[] = [0.1, 0.2, 0.3];
                }
                return $out;
            }

            public function dimensions(): int
            {
                return 3;
            }
        };
    }
}
