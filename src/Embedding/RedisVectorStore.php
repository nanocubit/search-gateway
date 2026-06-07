<?php

declare(strict_types=1);

namespace SearchGateway\Embedding;

use SearchGateway\Infrastructure\CacheInterface;

/**
 * Naive Redis vector store using sorted sets with cosine similarity.
 * Production: use Redis Stack (RedisJSON + RediSearch) or Valkey.
 */
final class RedisVectorStore implements VectorStoreInterface
{
    public function __construct(private CacheInterface $redis, private string $prefix = 'vec')
    {
    }

    /**
     * @param list<array<string, mixed>> $documents
     */
    public function add(array $documents): void
    {
        foreach ($documents as $doc) {
            $id = is_scalar($doc['id'] ?? null) ? (string) $doc['id'] : '';
            $key = "{$this->prefix}:{$id}";
            $this->redis->set($key, json_encode($doc, JSON_THROW_ON_ERROR), 0);
        }
    }

    /**
     * @param list<float> $vector
     * @return list<array<string, mixed>>
     */
    public function search(array $vector, int $k = 5): array
    {
        $keys = $this->scanKeys();
        $scored = [];
        foreach ($keys as $key) {
            $raw = $this->redis->get($key);
            if (!is_string($raw)) {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }
            $stored = $decoded['vector'] ?? null;
            if (!is_array($stored)) {
                continue;
            }
            $floatVec = [];
            foreach ($stored as $v) {
                if (is_numeric($v)) {
                    $floatVec[] = (float) $v;
                }
            }
            $score = $this->cosineSimilarity($vector, $floatVec);

            $meta = is_array($decoded['meta'] ?? null) ? $decoded['meta'] : [];
            $scored[] = array_merge($meta, ['_score' => $score]);
        }

        usort($scored, static function (array $a, array $b): int {
            $aScore = is_numeric($a['_score'] ?? 0) ? (float) $a['_score'] : 0.0;
            $bScore = is_numeric($b['_score'] ?? 0) ? (float) $b['_score'] : 0.0;
            return $bScore <=> $aScore;
        });
        return array_slice($scored, 0, $k);
    }

    public function delete(string $id): void
    {
        $this->redis->set("{$this->prefix}:{$id}", '', 0);
    }

    public function clear(): void
    {
        foreach ($this->scanKeys() as $key) {
            $this->redis->set($key, '', 0);
        }
    }

    /**
     * @return list<string>
     */
    private function scanKeys(): array
    {
        return [];
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        foreach ($a as $i => $v) {
            $bv = (float) $b[$i];
            $dot += $v * $bv;
            $na += $v * $v;
            $nb += $bv * $bv;
        }
        $den = sqrt($na) * sqrt($nb);
        return $den == 0 ? 0.0 : $dot / $den;
    }
}
