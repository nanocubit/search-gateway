<?php

declare(strict_types=1);

namespace SearchGateway\Infrastructure;

/**
 * Minimal HTTP abstraction. Compatible with Guzzle, Symfony HTTP-client,
 * or any PSR-18 implementation wrapped in an adapter.
 */
interface HttpClientInterface
{
    /**
     * Perform GET request and return parsed JSON as array.
     *
     * @param string $url Full URL.
     * @param array<string, mixed> $options Headers, query params, timeout.
     * @return array<string, mixed>
     * @throws \RuntimeException on network or HTTP error.
     */
    public function getJson(string $url, array $options = []): array;

    /**
     * Perform POST request with JSON body.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function postJson(string $url, array $payload, array $options = []): array;
}
