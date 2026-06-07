<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Graph;

use PHPUnit\Framework\TestCase;
use SearchGateway\Graph\KnowledgeGraphExtractor;
use SearchGateway\Tests\Support\MockLLMClient;

final class KnowledgeGraphExtractorTest extends TestCase
{
    public function testExtractsEntitiesAndRelationsFromCleanJson(): void
    {
        $json = json_encode([
            'entities' => [
                ['name' => 'PHP', 'type' => 'TECH'],
                ['name' => 'Rasmus Lerdorf', 'type' => 'PERSON'],
            ],
            'relations' => [
                ['from' => 'Rasmus Lerdorf', 'to' => 'PHP', 'type' => 'CREATED'],
            ],
        ]);
        $this->assertIsString($json);

        $llm = new MockLLMClient([$json]);
        $extractor = new KnowledgeGraphExtractor($llm);

        $result = $extractor->extract('Some text about PHP.');

        $this->assertCount(2, $result['entities']);
        $this->assertSame('PHP', $result['entities'][0]['name']);
        $this->assertSame('TECH', $result['entities'][0]['type']);
        $this->assertSame('Rasmus Lerdorf', $result['entities'][1]['name']);
        $this->assertCount(1, $result['relations']);
        $this->assertSame('Rasmus Lerdorf', $result['relations'][0]['from']);
        $this->assertSame('PHP', $result['relations'][0]['to']);
        $this->assertSame('CREATED', $result['relations'][0]['type']);
    }

    public function testExtractsJsonWrappedInMarkdownFences(): void
    {
        $inner = json_encode([
            'entities' => [['name' => 'Python', 'type' => 'TECH']],
            'relations' => [],
        ]);
        $this->assertIsString($inner);

        $wrapped = "```json\n{$inner}\n```";

        $llm = new MockLLMClient([$wrapped]);
        $extractor = new KnowledgeGraphExtractor($llm);

        $result = $extractor->extract('About Python.');

        $this->assertCount(1, $result['entities']);
        $this->assertSame('Python', $result['entities'][0]['name']);
        $this->assertSame([], $result['relations']);
    }

    public function testReturnsEmptyArraysOnInvalidJson(): void
    {
        $llm = new MockLLMClient(['not a json at all']);
        $extractor = new KnowledgeGraphExtractor($llm);

        $result = $extractor->extract('text');

        $this->assertSame([], $result['entities']);
        $this->assertSame([], $result['relations']);
    }

    public function testReturnsEmptyArraysOnEmptyLlmResponse(): void
    {
        $llm = new MockLLMClient(['']);
        $extractor = new KnowledgeGraphExtractor($llm);

        $result = $extractor->extract('text');

        $this->assertSame([], $result['entities']);
        $this->assertSame([], $result['relations']);
    }

    public function testFiltersOutMalformedEntities(): void
    {
        $json = json_encode([
            'entities' => [
                ['name' => 'PHP', 'type' => 'TECH'],      // valid
                ['name' => 'NoType'],                      // missing type -> filtered
                ['name' => ['nested' => 'array'], 'type' => 'TECH'],  // non-scalar name -> filtered
                'not-an-object',                            // not an array -> filtered
                ['name' => 123, 'type' => 'TECH'],         // int is scalar -> kept
            ],
            'relations' => [],
        ]);
        $this->assertIsString($json);

        $llm = new MockLLMClient([$json]);
        $extractor = new KnowledgeGraphExtractor($llm);

        $result = $extractor->extract('text');

        $this->assertCount(2, $result['entities'], 'PHP kept, 123 kept; NoType, nested, and non-object filtered');
        $names = array_map(static fn($n): string => (string) $n, array_column($result['entities'], 'name'));
        $this->assertContains('PHP', $names);
        $this->assertNotContains('NoType', $names);
    }

    public function testFiltersOutMalformedRelations(): void
    {
        $json = json_encode([
            'entities' => [],
            'relations' => [
                ['from' => 'A', 'to' => 'B', 'type' => 'X'],          // valid
                ['from' => 'A', 'to' => 'B'],                          // missing type -> filtered
                ['from' => ['nested' => 1], 'to' => 'B', 'type' => 'X'],  // non-scalar from -> filtered
                'invalid',                                              // not an array -> filtered
                ['from' => 1, 'to' => 2, 'type' => 3],                  // all ints (scalars) -> kept
            ],
        ]);
        $this->assertIsString($json);

        $llm = new MockLLMClient([$json]);
        $extractor = new KnowledgeGraphExtractor($llm);

        $result = $extractor->extract('text');

        $this->assertCount(2, $result['relations']);
        $this->assertSame('A', $result['relations'][0]['from']);
        $this->assertSame('B', $result['relations'][0]['to']);
        $this->assertSame('X', $result['relations'][0]['type']);
        $this->assertSame('1', $result['relations'][1]['from']);
        $this->assertSame('2', $result['relations'][1]['to']);
        $this->assertSame('3', $result['relations'][1]['type']);
    }

    public function testHandlesMissingEntitiesOrRelationsKeys(): void
    {
        $llm = new MockLLMClient(['{"entities":[]}']);
        $extractor = new KnowledgeGraphExtractor($llm);

        $result = $extractor->extract('text');
        $this->assertSame([], $result['entities']);
        $this->assertSame([], $result['relations']);
    }

    public function testExpandQueryFindsMatchingEntities(): void
    {
        $llm = new MockLLMClient();
        $extractor = new KnowledgeGraphExtractor($llm);

        $entities = [
            ['name' => 'PHP', 'type' => 'TECH'],
            ['name' => 'Python', 'type' => 'TECH'],
            ['name' => 'JavaScript', 'type' => 'TECH'],
        ];

        $related = $extractor->expandQuery('Compare PHP and Python performance', $entities);

        $this->assertContains('PHP', $related);
        $this->assertContains('Python', $related);
        $this->assertNotContains('JavaScript', $related);
    }

    public function testExpandQueryIsCaseInsensitive(): void
    {
        $llm = new MockLLMClient();
        $extractor = new KnowledgeGraphExtractor($llm);

        $entities = [['name' => 'PHP', 'type' => 'TECH']];
        $related = $extractor->expandQuery('php performance', $entities);

        $this->assertSame(['PHP'], $related);
    }

    public function testExpandQueryReturnsUniqueResults(): void
    {
        $llm = new MockLLMClient();
        $extractor = new KnowledgeGraphExtractor($llm);

        $entities = [
            ['name' => 'PHP', 'type' => 'TECH'],
            ['name' => 'PHP', 'type' => 'TECH'],  // duplicate
        ];

        $related = $extractor->expandQuery('PHP framework', $entities);

        $this->assertCount(1, $related);
        $this->assertSame('PHP', $related[0]);
    }

    public function testExpandQueryWithEmptyInputReturnsEmpty(): void
    {
        $llm = new MockLLMClient();
        $extractor = new KnowledgeGraphExtractor($llm);

        $this->assertSame([], $extractor->expandQuery('something', []));
    }

    public function testLlmPromptContainsTheInputText(): void
    {
        $llm = new MockLLMClient(['{"entities":[], "relations":[]}']);
        $extractor = new KnowledgeGraphExtractor($llm);

        $extractor->extract('PHP 8.4 is great');

        $this->assertCount(1, $llm->seenPrompts());
        $this->assertStringContainsString('PHP 8.4 is great', $llm->seenPrompts()[0]);
    }
}
