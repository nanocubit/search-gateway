<?php

declare(strict_types=1);

namespace SearchGateway\Tool;

/**
 * Optional contract for gateways that can describe their underlying HTTP request
 * (or set of requests) for a given search method. When a provider implements
 * this interface, AsyncMultiSearchGateway can dispatch its work concurrently
 * via the AsyncHttpClientInterface, achieving real parallel I/O in PHP-FPM.
 *
 * Gateways that do not implement this interface fall back to sequential calls.
 */
interface AsyncRequestBuilderInterface
{
    /**
     * Stable identifier for the upstream provider used as the job key
     * inside {@see AsyncMultiSearchGateway}. Required so concurrent
     * requests can be correlated with their results.
     */
    public function providerName(): string;

    /**
     * Build one or more HTTP request descriptors for a given search method.
     *
     * @param string $method  One of: searchWeb, searchNews, searchImages, llmContext, wordstat.
     * @param string $query   Raw query string.
     * @param array<string, mixed> $options  Caller-supplied options.
     * @return list<array{
     *     method: 'GET'|'POST',
     *     uri: string,
     *     headers: array<string, string>,
     *     body: ?string,
     *     provider: string,
     *     decode: callable(string): array<string, mixed>
     * }>
     */
    public function buildRequests(string $method, string $query, array $options): array;
}
