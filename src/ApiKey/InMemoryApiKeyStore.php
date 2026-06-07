<?php

declare(strict_types=1);

namespace SearchGateway\ApiKey;

final class InMemoryApiKeyStore implements ApiKeyStoreInterface
{
    /** @var array<string, ApiKey> */
    private array $byId = [];
    /** @var array<string, string> */
    private array $hashToId = [];

    public function save(ApiKey $key): void
    {
        $this->byId[$key->id()] = $key;
        $this->hashToId[$key->hash()] = $key->id();
    }

    public function findById(string $id): ?ApiKey
    {
        return $this->byId[$id] ?? null;
    }

    public function findByHash(string $hash): ?ApiKey
    {
        $id = $this->hashToId[$hash] ?? null;
        if ($id === null) {
            return null;
        }
        return $this->byId[$id] ?? null;
    }

    /**
     * @return list<ApiKey>
     */
    public function all(): array
    {
        return array_values($this->byId);
    }

    public function delete(string $id): bool
    {
        $key = $this->byId[$id] ?? null;
        if ($key === null) {
            return false;
        }
        unset($this->byId[$id], $this->hashToId[$key->hash()]);
        return true;
    }

    public function count(): int
    {
        return count($this->byId);
    }
}
