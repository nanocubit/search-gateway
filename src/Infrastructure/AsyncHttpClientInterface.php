<?php

declare(strict_types=1);

namespace SearchGateway\Infrastructure;

/**
 * Async HTTP client for concurrent multi-provider requests.
 * Returns promises / futures resolved to arrays.
 */
interface AsyncHttpClientInterface
{
    /**
     * @param array<string, mixed> $options
     * @return object Promise-like object with ->wait(): array<string, mixed>
     */
    public function getJsonAsync(string $url, array $options = []): object;

    /**
     * @param list<object> $promises
     * @return list<array<string, mixed>>
     */
    public function waitAll(array $promises): array;
}
