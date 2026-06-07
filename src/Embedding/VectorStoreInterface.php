<?php

declare(strict_types=1);

namespace SearchGateway\Embedding;

/**
 * Vector store abstraction: Redis Vector, pgvector, Qdrant, Pinecone, etc.
 */
interface VectorStoreInterface
{
    /**
     * Add documents with embeddings.
     *
     * @param list<array{id: string, vector: list<float>, meta: array<string, mixed>}> $documents
     */
    public function add(array $documents): void;

    /**
     * Search similar vectors.
     *
     * @param list<float> $vector
     * @param int $k Top-k results
     * @return list<array<string, mixed>>
     */
    public function search(array $vector, int $k = 5): array;

    public function delete(string $id): void;

    public function clear(): void;
}
