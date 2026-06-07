<?php

declare(strict_types=1);

namespace SearchGateway\Infrastructure;

/**
 * Minimal event bus for search lifecycle hooks.
 */
interface EventBusInterface
{
    /**
     * @param callable(array<string, mixed>): void $listener
     */
    public function subscribe(string $event, callable $listener): void;

    /**
     * @param array<string, mixed> $payload
     */
    public function emit(string $event, array $payload): void;
}
