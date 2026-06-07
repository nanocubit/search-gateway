<?php

declare(strict_types=1);

namespace SearchGateway\Embedding;

/**
 * Абстракция над embedding-моделями (OpenAI, YandexGPT, local).
 */
interface EmbeddingInterface
{
    /**
     * Embed single text -> vector.
     *
     * @return list<float>
     */
    public function embed(string $text): array;

    /**
     * Embed batch -> list of vectors.
     *
     * @param list<string> $texts
     * @return list<list<float>>
     */
    public function embedBatch(array $texts): array;

    /**
     * Dimensionality of vectors.
     */
    public function dimensions(): int;
}
