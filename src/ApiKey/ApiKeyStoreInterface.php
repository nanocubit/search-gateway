<?php

declare(strict_types=1);

namespace SearchGateway\ApiKey;

interface ApiKeyStoreInterface
{
    public function save(ApiKey $key): void;

    public function findById(string $id): ?ApiKey;

    public function findByHash(string $hash): ?ApiKey;

    /**
     * @return list<ApiKey>
     */
    public function all(): array;

    public function delete(string $id): bool;

    public function count(): int;
}
