<?php

declare(strict_types=1);

namespace SearchGateway\Observability;

final class InMemoryAuditLogger implements AuditLoggerInterface
{
    private int $maxEvents = 10000;
    /** @var list<array<string, mixed>> */
    private array $events = [];

    public function log(string $action, string $actor, array $context = []): void
    {
        $this->events[] = [
            'ts' => microtime(true),
            'action' => $action,
            'actor' => $actor,
            'context' => $context,
        ];
        if (count($this->events) > $this->maxEvents) {
            array_shift($this->events);
        }
    }

    public function events(int $limit = 100): array
    {
        if ($limit <= 0) {
            return $this->events;
        }
        return array_slice($this->events, -$limit);
    }

    public function count(): int
    {
        return count($this->events);
    }
}
