<?php

declare(strict_types=1);

namespace SearchGateway\Indexer;

use SearchGateway\Chunking\DocumentSplitter;
use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Embedding\EmbeddingInterface;
use SearchGateway\Embedding\VectorStoreInterface;

/**
 * Индексирует web-страницы в vector store для быстрого semantic search.
 * Идея из LlamaIndex: любой источник -> embeddings -> vector store.
 */
final class SearchIndexer
{
    public function __construct(
        private SearchGatewayInterface $search,
        private EmbeddingInterface $embedding,
        private VectorStoreInterface $vectorStore,
        private DocumentSplitter $splitter
    ) {
    }

    /**
     * Индексировать результаты поиска по запросу.
     *
     * @param array<string, mixed> $options
     */
    public function indexSearchResults(string $query, array $options = []): void
    {
        $docs = $this->search->llmContext($query, $options);

        foreach ($docs as $doc) {
            $passage = (string) $doc['passage'];
            if ($passage === '') {
                continue;
            }

            $chunks = $this->splitter->split($passage, [
                'strategy' => 'recursive',
                'chunk_size' => 500,
                'overlap' => 50,
            ]);

            $texts = [];
            foreach ($chunks as $chunk) {
                $texts[] = (string) $chunk['text'];
            }
            $vectors = $this->embedding->embedBatch($texts);

            $toStore = [];
            $i = 0;
            foreach ($chunks as $chunk) {
                $url = (string) $doc['url'];
                $chunkId = (string) $chunk['id'];
                $chunkText = (string) $chunk['text'];
                $toStore[] = [
                    'id' => md5($url . ':' . $chunkId),
                    'vector' => $vectors[$i] ?? [],
                    'meta' => [
                        'url' => $url,
                        'title' => (string) $doc['title'],
                        'domain' => (string) $doc['domain'],
                        'passage' => $chunkText,
                        'source_query' => $query,
                    ],
                ];
                $i++;
            }

            $this->vectorStore->add($toStore);
        }
    }

    /**
     * Semantic search по проиндексированным документам.
     *
     * @return list<array<string, mixed>>
     */
    public function semanticSearch(string $query, int $k = 5): array
    {
        $vector = $this->embedding->embed($query);
        return $this->vectorStore->search($vector, $k);
    }
}
