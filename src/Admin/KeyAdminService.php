<?php

declare(strict_types=1);

namespace SearchGateway\Admin;

use SearchGateway\ApiKey\ApiKey;
use SearchGateway\ApiKey\ApiKeyManager;

final class KeyAdminService
{
    public function __construct(private readonly ApiKeyManager $manager)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $rows = [];
        foreach ($this->manager->list() as $key) {
            $rows[] = $this->serialise($key, null);
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $owner = $data['owner'] ?? null;
        if (!is_string($owner) || $owner === '') {
            throw new \InvalidArgumentException('Field "owner" is required and must be a non-empty string');
        }
        $scopes = $data['scopes'] ?? [];
        if (!is_array($scopes)) {
            throw new \InvalidArgumentException('Field "scopes" must be an array');
        }
        $scopes = array_values(array_filter($scopes, 'is_string'));

        $rateLimit = null;
        if (isset($data['rateLimit']) && is_array($data['rateLimit'])) {
            $limit = $data['rateLimit']['limit'] ?? null;
            $window = $data['rateLimit']['window'] ?? null;
            if (is_int($limit) && is_int($window)) {
                $rateLimit = ['limit' => $limit, 'window' => $window];
            }
        }

        $expiresAt = $data['expiresAt'] ?? null;
        if ($expiresAt !== null && !is_int($expiresAt)) {
            throw new \InvalidArgumentException('Field "expiresAt" must be int or null');
        }

        [$raw, $key] = $this->manager->create($owner, $scopes, $rateLimit, $expiresAt);
        return $this->serialise($key, $raw);
    }

    public function revoke(string $id): bool
    {
        return $this->manager->revoke($id);
    }

    public function delete(string $id): bool
    {
        return $this->manager->delete($id);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $key = $this->manager->find($id);
        return $key === null ? null : $this->serialise($key, null);
    }

    public function count(): int
    {
        return count($this->manager->list());
    }

    /**
     * @return array<string, mixed>
     */
    private function serialise(ApiKey $key, ?string $raw): array
    {
        $row = [
            'id' => $key->id(),
            'prefix' => $key->prefix(),
            'owner' => $key->owner(),
            'scopes' => $key->scopes(),
            'rateLimit' => $key->rateLimit(),
            'createdAt' => $key->createdAt(),
            'expiresAt' => $key->expiresAt(),
            'revokedAt' => $key->revokedAt(),
            'active' => $key->isActive(time()),
        ];
        if ($raw !== null) {
            $row['rawKey'] = $raw;
        }
        return $row;
    }
}
