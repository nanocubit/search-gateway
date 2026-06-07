<?php

declare(strict_types=1);

namespace SearchGateway\ApiKey;

interface ApiKeyInterface
{
    public function id(): string;

    /**
     * @return list<string>
     */
    public function scopes(): array;

    /**
     * @return array{limit: int, window: int}|null
     */
    public function rateLimit(): ?array;

    public function owner(): string;

    public function createdAt(): int;

    public function expiresAt(): ?int;

    public function revokedAt(): ?int;

    public function isRevoked(): bool;

    public function isExpired(int $now): bool;

    public function isActive(int $now): bool;
}
