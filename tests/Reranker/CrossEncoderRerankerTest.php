<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Reranker;

use PHPUnit\Framework\TestCase;
use SearchGateway\Reranker\CrossEncoderReranker;
use SearchGateway\Tests\Support\MockLLMClient;

final class CrossEncoderRerankerTest extends TestCase
{
    public function testReturnsEmptyForEmptyDocs(): void
    {
        $llm = new MockLLMClient();
        $reranker = new CrossEncoderReranker($llm);
        $this->assertSame([], $reranker->rerank([], 'anything'));
    }

    public function testSingleModeScoresEachDocOnce(): void
    {
        $llm = new MockLLMClient(['0.9', '0.1', '0.5']);
        $reranker = new CrossEncoderReranker($llm, mode: CrossEncoderReranker::MODE_SINGLE);

        $docs = [
            ['title' => 'A', 'passage' => 'A passage'],
            ['title' => 'B', 'passage' => 'B passage'],
            ['title' => 'C', 'passage' => 'C passage'],
        ];

        $result = $reranker->rerank($docs, 'q', topK: 3);

        $this->assertCount(3, $llm->seenPrompts(), 'one LLM call per doc');
        $this->assertCount(3, $result);
        $this->assertSame('A', $result[0]['title'], 'highest score first');
        $this->assertSame(0.9, $result[0]['_rerank_score']);
        $this->assertSame('C', $result[1]['title']);
        $this->assertSame(0.5, $result[1]['_rerank_score']);
        $this->assertSame('B', $result[2]['title']);
        $this->assertSame(0.1, $result[2]['_rerank_score']);
    }

    public function testSingleModeHandlesClamping(): void
    {
        $llm = new MockLLMClient(['1.5', '-0.5']);
        $reranker = new CrossEncoderReranker($llm, mode: CrossEncoderReranker::MODE_SINGLE);

        $docs = [
            ['title' => 'A', 'passage' => 'p'],
            ['title' => 'B', 'passage' => 'p'],
        ];

        $result = $reranker->rerank($docs, 'q');

        $this->assertSame(1.0, $result[0]['_rerank_score'], 'clamped to 1.0');
        $this->assertSame(0.0, $result[1]['_rerank_score'], 'clamped to 0.0');
    }

    public function testSingleModeReturnsNeutralOnUnparseableNumber(): void
    {
        $llm = new MockLLMClient(['not a number at all']);
        $reranker = new CrossEncoderReranker($llm, mode: CrossEncoderReranker::MODE_SINGLE);

        $result = $reranker->rerank([['title' => 'A', 'passage' => 'p']], 'q');

        $this->assertSame(0.5, $result[0]['_rerank_score']);
    }

    public function testSingleModeSupportsDescriptionAndSnippetFallbacks(): void
    {
        $llm = new MockLLMClient(['0.7']);
        $reranker = new CrossEncoderReranker($llm, mode: CrossEncoderReranker::MODE_SINGLE);

        $result = $reranker->rerank([
            ['title' => 'desc-only', 'description' => 'd-text'],
            ['title' => 'snippet-only', 'snippet' => 's-text'],
        ], 'q');

        $this->assertCount(2, $llm->seenPrompts());
        $prompt = $llm->seenPrompts()[0];
        $this->assertStringContainsString('d-text', $prompt);
        $prompt2 = $llm->seenPrompts()[1];
        $this->assertStringContainsString('s-text', $prompt2);
        $this->assertSame(0.7, $result[0]['_rerank_score']);
    }

    public function testSingleModeTopKLimitsResults(): void
    {
        $llm = new MockLLMClient(['0.1', '0.2', '0.3']);
        $reranker = new CrossEncoderReranker($llm, mode: CrossEncoderReranker::MODE_SINGLE);

        $result = $reranker->rerank(
            [
                ['title' => 'A', 'passage' => 'p'],
                ['title' => 'B', 'passage' => 'p'],
                ['title' => 'C', 'passage' => 'p'],
            ],
            'q',
            topK: 2
        );

        $this->assertCount(2, $result);
        $this->assertSame('C', $result[0]['title']);
        $this->assertSame('B', $result[1]['title']);
    }

    public function testBatchModeSinglePrompt(): void
    {
        $batchResponse = "0.8\n0.2\n0.6";
        $llm = new MockLLMClient([$batchResponse]);
        $reranker = new CrossEncoderReranker($llm, mode: CrossEncoderReranker::MODE_BATCH);

        $docs = [
            ['title' => 'A', 'passage' => 'pA'],
            ['title' => 'B', 'passage' => 'pB'],
            ['title' => 'C', 'passage' => 'pC'],
        ];

        $result = $reranker->rerank($docs, 'q');

        $this->assertCount(1, $llm->seenPrompts(), 'batch mode: single LLM call');
        $this->assertSame('A', $result[0]['title']);
        $this->assertSame(0.8, $result[0]['_rerank_score']);
        $this->assertSame('C', $result[1]['title']);
        $this->assertSame('B', $result[2]['title']);
    }

    public function testBatchModeHandlesPartialResponse(): void
    {
        $llm = new MockLLMClient(["0.9\n0.1"]);
        $reranker = new CrossEncoderReranker($llm, mode: CrossEncoderReranker::MODE_BATCH);

        $result = $reranker->rerank([
            ['title' => 'A', 'passage' => 'pA'],
            ['title' => 'B', 'passage' => 'pB'],
            ['title' => 'C', 'passage' => 'pC'],
        ], 'q');

        $this->assertCount(3, $result);
        // scores map to docs: A=0.9, B=0.1, C=0.5 (default). Sorted: A, C, B.
        $this->assertSame('A', $result[0]['title']);
        $this->assertSame(0.9, $result[0]['_rerank_score']);
        $this->assertSame('C', $result[1]['title']);
        $this->assertSame(0.5, $result[1]['_rerank_score'], 'missing index -> neutral 0.5');
        $this->assertSame('B', $result[2]['title']);
        $this->assertSame(0.1, $result[2]['_rerank_score']);
    }

    public function testBatchModeHandlesUnparseableResponse(): void
    {
        $llm = new MockLLMClient(['gibberish with no numbers at all']);
        $reranker = new CrossEncoderReranker($llm, mode: CrossEncoderReranker::MODE_BATCH);

        $result = $reranker->rerank([
            ['title' => 'A', 'passage' => 'pA'],
            ['title' => 'B', 'passage' => 'pB'],
        ], 'q');

        $this->assertCount(2, $result);
        $this->assertSame(0.5, $result[0]['_rerank_score']);
        $this->assertSame(0.5, $result[1]['_rerank_score']);
    }

    public function testBatchModeClampsScores(): void
    {
        $llm = new MockLLMClient(["1.7\n-0.5"]);
        $reranker = new CrossEncoderReranker($llm, mode: CrossEncoderReranker::MODE_BATCH);

        $result = $reranker->rerank([
            ['title' => 'A', 'passage' => 'pA'],
            ['title' => 'B', 'passage' => 'pB'],
        ], 'q');

        $this->assertSame(1.0, $result[0]['_rerank_score']);
        $this->assertSame(0.0, $result[1]['_rerank_score']);
    }

    public function testCustomSystemPromptIsUsed(): void
    {
        $llm = new MockLLMClient(['0.42']);
        $reranker = new CrossEncoderReranker(
            $llm,
            systemPrompt: 'Custom instruction here',
            mode: CrossEncoderReranker::MODE_SINGLE
        );

        $reranker->rerank([['title' => 'A', 'passage' => 'p']], 'q');

        $this->assertStringContainsString('Custom instruction here', $llm->seenPrompts()[0]);
        $this->assertStringNotContainsString('Respond with ONLY', $llm->seenPrompts()[0]);
    }

    public function testRerankStripsScoreKey(): void
    {
        $llm = new MockLLMClient(['0.5']);
        $reranker = new CrossEncoderReranker($llm, mode: CrossEncoderReranker::MODE_SINGLE);

        $result = $reranker->rerank([['title' => 'A', 'passage' => 'p', 'url' => 'u']], 'q', topK: 1);

        $this->assertArrayHasKey('_rerank_score', $result[0]);
        $this->assertArrayHasKey('url', $result[0]);
        $this->assertArrayHasKey('title', $result[0]);
    }
}
