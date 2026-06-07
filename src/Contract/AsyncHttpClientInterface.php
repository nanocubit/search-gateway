<?php

declare(strict_types=1);

namespace SearchGateway\Contract;

use SearchGateway\Infrastructure\LoggerInterface;

/**
 * Concurrent HTTP client for multi-provider fan-out.
 * Realises a true parallel HTTP dispatch (e.g. via GuzzleHttp\Pool) suitable for PHP-FPM.
 */
interface AsyncHttpClientInterface
{
    /**
     * Dispatch many HTTP requests concurrently and gather results.
     *
     * Job shape:
     *  [
     *    'method'   => 'GET'|'POST'|...,
     *    'uri'      => 'https://...',
     *    'headers'  => array<string, string>,
     *    'body'     => string|null,
     *    'provider' => 'brave',
     *    'decode'   => callable(string $rawBody): array,
     *  ]
     *
     * Options:
     *  - concurrency: int|null  cap concurrent in-flight requests
     *  - timeout:     float     per-request timeout in seconds
     *  - failFast:    bool      throw on first failure (default: false, failures are collected)
     *
     * @param array<string, array<string, mixed>> $jobs
     * @param array<string, mixed> $options
     * @return array<string, array{
     *     success: bool,
     *     value: array<string, mixed>,
     *     error: ?string,
     *     provider: ?string,
     *     latency_ms: float,
     *     status: ?int,
     * }>
     */
    public function runConcurrent(array $jobs, array $options = []): array;
}
