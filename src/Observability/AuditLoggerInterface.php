<?php

declare(strict_types=1);

namespace SearchGateway\Observability;

interface AuditLoggerInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function log(string $action, string $actor, array $context = []): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function events(int $limit = 100): array;

    public function count(): int;
}
