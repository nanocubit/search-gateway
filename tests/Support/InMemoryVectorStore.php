<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Support;

use SearchGateway\Embedding\VectorStoreInterface;

/**
 * In-memory VectorStore for unit tests. Stores documents keyed by id and
 * returns them all on search() (no real similarity is computed).
 */
final class InMemoryVectorStore implements VectorStoreInterface
{
    /** @var list<array{id: string, vector: list<float>, meta: array<string, mixed>}> */
    private array $documents = [];

    public int $searchCalls = 0;

    public int $lastK = 0;

    /** @var list<float>|null */
    public ?array $lastQueryVector = null;

    /**
     * @param list<array{id: string, vector: list<float>, meta: array<string, mixed>}> $documents
     */
    public function __construct(array $documents = [])
    {
        foreach ($documents as $doc) {
            $this->add([$doc]);
        }
    }

    /**
     * @param list<array{id: string, vector: list<float>, meta: array<string, mixed>}> $documents
     */
    public function add(array $documents): void
    {
        foreach ($documents as $doc) {
            $this->documents[] = $doc;
        }
    }

    /**
     * @param list<float> $vector
     * @return list<array<string, mixed>>
     */
    public function search(array $vector, int $k = 5): array
    {
        $this->searchCalls++;
        $this->lastK = $k;
        $this->lastQueryVector = $vector;

        $out = [];
        $count = min($k, count($this->documents));
        for ($i = 0; $i < $count; $i++) {
            $doc = $this->documents[$i];
            $out[] = $doc['meta'];
        }
        return $out;
    }

    public function delete(string $id): void
    {
        $this->documents = array_values(array_filter(
            $this->documents,
            static fn(array $doc): bool => $doc['id'] !== $id
        ));
    }

    public function clear(): void
    {
        $this->documents = [];
    }
}
