<?php

declare(strict_types=1);

namespace SearchGateway\ApiKey;

final class ApiKey implements ApiKeyInterface
{
    /**
     * @param list<string> $scopes
     * @param array{limit: int, window: int}|null $rateLimit
     */
    public function __construct(
        private readonly string $id,
        private readonly string $hash,
        private readonly array $scopes,
        private readonly ?array $rateLimit,
        private readonly string $owner,
        private readonly int $createdAt,
        private readonly ?int $expiresAt = null,
        private readonly ?int $revokedAt = null,
        private readonly ?string $prefix = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function hash(): string
    {
        return $this->hash;
    }

    public function prefix(): string
    {
        return $this->prefix ?? $this->id;
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        return $this->scopes;
    }

    /**
     * @return array{limit: int, window: int}|null
     */
    public function rateLimit(): ?array
    {
        return $this->rateLimit;
    }

    public function owner(): string
    {
        return $this->owner;
    }

    public function createdAt(): int
    {
        return $this->createdAt;
    }

    public function expiresAt(): ?int
    {
        return $this->expiresAt;
    }

    public function revokedAt(): ?int
    {
        return $this->revokedAt;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isExpired(int $now): bool
    {
        return $this->expiresAt !== null && $now >= $this->expiresAt;
    }

    public function isActive(int $now): bool
    {
        return !$this->isRevoked() && !$this->isExpired($now);
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true) || in_array('*', $this->scopes, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'hash' => $this->hash,
            'prefix' => $this->prefix ?? $this->id,
            'scopes' => $this->scopes,
            'rateLimit' => $this->rateLimit,
            'owner' => $this->owner,
            'createdAt' => $this->createdAt,
            'expiresAt' => $this->expiresAt,
            'revokedAt' => $this->revokedAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? '';
        $hash = $data['hash'] ?? '';
        $owner = $data['owner'] ?? '';
        $createdAt = $data['createdAt'] ?? 0;
        if (!is_string($id) || !is_string($hash) || !is_string($owner)) {
            throw new \InvalidArgumentException('ApiKey: id, hash, owner must be strings');
        }
        if (!is_int($createdAt)) {
            throw new \InvalidArgumentException('ApiKey: createdAt must be int');
        }
        $scopes = $data['scopes'] ?? [];
        if (!is_array($scopes)) {
            throw new \InvalidArgumentException('ApiKey: scopes must be array');
        }
        $scopes = array_values(array_filter($scopes, 'is_string'));
        $rateLimit = $data['rateLimit'] ?? null;
        if ($rateLimit !== null && !is_array($rateLimit)) {
            throw new \InvalidArgumentException('ApiKey: rateLimit must be array or null');
        }
        $expiresAt = $data['expiresAt'] ?? null;
        if ($expiresAt !== null && !is_int($expiresAt)) {
            throw new \InvalidArgumentException('ApiKey: expiresAt must be int or null');
        }
        $revokedAt = $data['revokedAt'] ?? null;
        if ($revokedAt !== null && !is_int($revokedAt)) {
            throw new \InvalidArgumentException('ApiKey: revokedAt must be int or null');
        }
        $prefix = $data['prefix'] ?? null;
        if ($prefix !== null && !is_string($prefix)) {
            throw new \InvalidArgumentException('ApiKey: prefix must be string or null');
        }

        return new self(
            id: $id,
            hash: $hash,
            scopes: $scopes,
            rateLimit: $rateLimit !== null ? self::normaliseRateLimit($rateLimit) : null,
            owner: $owner,
            createdAt: $createdAt,
            expiresAt: $expiresAt,
            revokedAt: $revokedAt,
            prefix: $prefix,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array{limit: int, window: int}
     */
    private static function normaliseRateLimit(array $data): array
    {
        $limit = $data['limit'] ?? 0;
        $window = $data['window'] ?? 60;
        if (!is_int($limit) || !is_int($window)) {
            throw new \InvalidArgumentException('ApiKey: rateLimit.limit and rateLimit.window must be int');
        }
        return ['limit' => $limit, 'window' => $window];
    }
}
