<?php

declare(strict_types=1);

namespace SearchGateway\ApiKey;

use SearchGateway\Contract\SearchGatewayException;

final class ApiKeyManager
{
    /**
     * @param (callable(): mixed)|null $clock Function returning current unix timestamp; defaults to time().
     */
    public function __construct(
        private readonly ApiKeyStoreInterface $store,
        private readonly ?ApiKeyHasher $hasher = null,
        private readonly mixed $clock = null,
    ) {
    }

    private function hasher(): ApiKeyHasher
    {
        $h = $this->hasher;
        return $h ?? new ApiKeyHasher();
    }

    /**
     * Create a new API key. Returns [rawKey, ApiKey] — the raw key is shown only once.
     *
     * @param list<string> $scopes
     * @param array{limit: int, window: int}|null $rateLimit
     * @param int<1, 1024> $randomBytes
     * @return array{0: string, 1: ApiKey}
     */
    public function create(
        string $owner,
        array $scopes = [],
        ?array $rateLimit = null,
        ?int $expiresAt = null,
        int $randomBytes = 32,
    ): array {
        $hasher = $this->hasher();
        $rawKey = $hasher->generateRawKey($randomBytes);
        $hash = $hasher->hash($rawKey);
        $now = $this->now();
        $id = bin2hex(random_bytes(8));
        $key = new ApiKey(
            id: $id,
            hash: $hash,
            scopes: array_values(array_filter($scopes, 'is_string')),
            rateLimit: $rateLimit,
            owner: $owner,
            createdAt: $now,
            expiresAt: $expiresAt,
            prefix: $hasher->publicPrefix($rawKey),
        );
        $this->store->save($key);
        return [$rawKey, $key];
    }

    /**
     * Verify a raw API key. Returns the key on success, null on failure.
     */
    public function verify(string $rawKey): ?ApiKey
    {
        if ($rawKey === '' || !str_starts_with($rawKey, ApiKeyHasher::PREFIX)) {
            return null;
        }
        $candidates = $this->store->all();
        $hasher = $this->hasher();
        foreach ($candidates as $candidate) {
            if (!$hasher->verify($rawKey, $candidate->hash())) {
                continue;
            }
            if (!$candidate->isActive($this->now())) {
                return null;
            }
            return $candidate;
        }
        return null;
    }

    /**
     * Verify and assert that the key has at least one of the required scopes.
     *
     * @param list<string> $requiredScopes
     */
    public function verifyWithScopes(string $rawKey, array $requiredScopes): ?ApiKey
    {
        $key = $this->verify($rawKey);
        if ($key === null) {
            return null;
        }
        foreach ($requiredScopes as $scope) {
            if (!is_string($scope)) {
                continue;
            }
            if (!$key->hasScope($scope)) {
                return null;
            }
        }
        return $key;
    }

    /**
     * Revoke a key by id. Returns true on success.
     */
    public function revoke(string $id): bool
    {
        $existing = $this->store->findById($id);
        if ($existing === null || $existing->isRevoked()) {
            return false;
        }
        $revoked = new ApiKey(
            id: $existing->id(),
            hash: $existing->hash(),
            scopes: $existing->scopes(),
            rateLimit: $existing->rateLimit(),
            owner: $existing->owner(),
            createdAt: $existing->createdAt(),
            expiresAt: $existing->expiresAt(),
            revokedAt: $this->now(),
            prefix: $existing->prefix(),
        );
        $this->store->save($revoked);
        return true;
    }

    public function find(string $id): ?ApiKey
    {
        return $this->store->findById($id);
    }

    /**
     * @return list<ApiKey>
     */
    public function list(): array
    {
        return $this->store->all();
    }

    public function delete(string $id): bool
    {
        return $this->store->delete($id);
    }

    /**
     * Throw a 401/403 exception on invalid auth — useful for middleware.
     *
     * @param list<string> $requiredScopes
     */
    public function authenticate(string $rawKey, array $requiredScopes = []): ApiKey
    {
        $key = $requiredScopes === []
            ? $this->verify($rawKey)
            : $this->verifyWithScopes($rawKey, $requiredScopes);

        if ($key !== null) {
            return $key;
        }

        if ($this->verify($rawKey) === null) {
            throw new SearchGatewayException('Invalid or expired API key', 401);
        }
        throw new SearchGatewayException('Insufficient scope for this route', 403);
    }

    private function now(): int
    {
        if (is_callable($this->clock)) {
            $value = ($this->clock)();
            return is_int($value) ? $value : time();
        }
        return time();
    }
}
