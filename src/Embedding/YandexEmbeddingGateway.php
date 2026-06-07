<?php

declare(strict_types=1);

namespace SearchGateway\Embedding;

use SearchGateway\Infrastructure\HttpClientInterface;

/**
 * YandexGPT Embeddings (yandexgpt-lite, yandexgpt).
 */
final class YandexEmbeddingGateway implements EmbeddingInterface
{
    public function __construct(
        private HttpClientInterface $http,
        private string $apiKey,
        private string $folderId,
        private string $model = 'emb://yandexgpt-lite/latest',
        private string $baseUri = 'https://llm.api.cloud.yandex.net'
    ) {
    }

    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0];
    }

    /**
     * @return list<list<float>>
     */
    public function embedBatch(array $texts): array
    {
        $results = [];
        foreach ($texts as $text) {
            $resp = $this->http->postJson(
                rtrim($this->baseUri, '/') . '/foundationModels/v1/textEmbedding',
                [
                    'modelUri' => $this->model,
                    'text' => $text,
                ],
                [
                    'headers' => [
                        'Authorization' => 'Api-Key ' . $this->apiKey,
                        'x-folder-id' => $this->folderId,
                        'Content-Type' => 'application/json',
                    ],
                ]
            );
            $embedding = is_array($resp['embedding'] ?? null) ? $resp['embedding'] : [];
            $values = [];
            foreach ($embedding as $v) {
                if (is_numeric($v)) {
                    $values[] = (float) $v;
                }
            }
            $results[] = $values;
        }
        return $results;
    }

    public function dimensions(): int
    {
        return 1024; // YandexGPT-lite embeddings
    }
}
