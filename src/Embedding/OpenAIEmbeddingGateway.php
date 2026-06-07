<?php

declare(strict_types=1);

namespace SearchGateway\Embedding;

use SearchGateway\Infrastructure\HttpClientInterface;

/**
 * OpenAI text-embedding-3-small/ada-002 gateway.
 */
final class OpenAIEmbeddingGateway implements EmbeddingInterface
{
    public function __construct(
        private HttpClientInterface $http,
        private string $apiKey,
        private string $model = 'text-embedding-3-small',
        private string $baseUri = 'https://api.openai.com/v1'
    ) {
    }

    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0];
    }

    public function embedBatch(array $texts): array
    {
        $resp = $this->http->postJson(
            rtrim($this->baseUri, '/') . '/embeddings',
            ['model' => $this->model, 'input' => $texts],
            ['headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Content-Type' => 'application/json']]
        );

        $data = is_array($resp['data'] ?? null) ? $resp['data'] : [];
        $items = [];
        foreach ($data as $item) {
            if (is_array($item) && is_array($item['embedding'] ?? null)) {
                $items[] = array_values($item['embedding']);
            }
        }
        return $items;
    }

    public function dimensions(): int
    {
        return match ($this->model) {
            'text-embedding-3-small' => 1536,
            'text-embedding-3-large' => 3072,
            default => 1536,
        };
    }
}
