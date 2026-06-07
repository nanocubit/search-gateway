<?php

declare(strict_types=1);

namespace SearchGateway\Infrastructure;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use SearchGateway\Contract\AsyncHttpClientInterface;
use SearchGateway\Contract\SearchGatewayException;

/**
 * Concurrent HTTP fan-out via GuzzleHttp\Pool. Real parallel I/O for PHP-FPM.
 *
 * @api Requires guzzlehttp/guzzle ^7.0 and guzzlehttp/psr7 ^2.0
 */
final class GuzzleConcurrentHttpClient implements AsyncHttpClientInterface
{
    /** @var array<string, float> */
    private array $startedAt = [];

    public function __construct(
        private readonly Client $client,
        private readonly LoggerInterface $logger,
        private readonly int $defaultConcurrency = 5,
    ) {
    }

    public function runConcurrent(array $jobs, array $options = []): array
    {
        if ($jobs === []) {
            return [];
        }

        $concurrencyRaw = $options['concurrency'] ?? null;
        $concurrency = is_int($concurrencyRaw)
            ? $concurrencyRaw
            : min(count($jobs), $this->defaultConcurrency);
        $concurrency = max(1, $concurrency);

        $failFastRaw = $options['failFast'] ?? null;
        $failFast = is_bool($failFastRaw) ? $failFastRaw : false;

        $this->startedAt = [];
        $results = [];

        $requestGenerator = function () use ($jobs): \Generator {
            foreach ($jobs as $key => $job) {
                /** @var array{method?: string, uri?: string, headers?: array<string, string>, body?: string|null, provider?: string, decode?: callable(string): mixed} $job */
                $this->startedAt[(string) $key] = microtime(true);

                $methodRaw = $job['method'] ?? null;
                $method = is_string($methodRaw) ? strtoupper($methodRaw) : 'GET';

                $uriRaw = $job['uri'] ?? null;
                $uri = is_string($uriRaw) ? $uriRaw : '';

                $headers = $job['headers'] ?? [];
                if (!is_array($headers)) {
                    $headers = [];
                }

                $body = $job['body'] ?? null;

                yield (string) $key => new Request($method, $uri, $headers, $body);
            }
        };

        $pool = new Pool($this->client, $requestGenerator(), [
            'concurrency' => $concurrency,
            'fulfilled' => function (ResponseInterface $response, string $key) use (&$results, $jobs): void {
                $job = $jobs[$key] ?? [];
                $decoder = $job['decode'] ?? null;
                $raw = (string) $response->getBody();
                $start = $this->startedAt[$key] ?? microtime(true);

                try {
                    $value = is_callable($decoder) ? $decoder($raw) : json_decode($raw, true);
                    if (!is_array($value)) {
                        $value = [];
                    }
                    $providerRaw = $job['provider'] ?? null;
                    $results[$key] = [
                        'success' => true,
                        'value' => $value,
                        'error' => null,
                        'provider' => is_string($providerRaw) ? $providerRaw : null,
                        'latency_ms' => (microtime(true) - $start) * 1000.0,
                        'status' => $response->getStatusCode(),
                    ];
                } catch (\Throwable $e) {
                    $this->logger->error("Concurrent decode failed: {$key}", ['error' => $e->getMessage()]);
                    $results[$key] = $this->failure($key, $job, $e->getMessage(), $start);
                }
            },
            'rejected' => function (\Throwable $reason, string $key) use (&$results, $jobs, $failFast): void {
                $job = $jobs[$key] ?? [];
                $start = $this->startedAt[$key] ?? microtime(true);
                $message = $reason->getMessage();
                $status = null;

                if ($reason instanceof RequestException && $reason->hasResponse()) {
                    $status = $reason->getResponse()?->getStatusCode();
                    $message .= ' | Status: ' . $status;
                }

                $this->logger->error("Concurrent request failed: {$key}", [
                    'error' => $message,
                    'provider' => $job['provider'] ?? null,
                ]);

                $results[$key] = $this->failure($key, $job, $message, $start, $status);

                if ($failFast) {
                    $providerRaw = $job['provider'] ?? null;
                    throw new SearchGatewayException(
                        sprintf("Concurrent request '%s' failed: %s", $key, $message),
                        502,
                        $reason,
                        is_string($providerRaw) ? $providerRaw : null,
                    );
                }
            },
        ]);

        $pool->promise()->wait();

        return $results;
    }

    /**
     * @param array<string, mixed> $job
     * @return array{success: bool, value: array<string, mixed>, error: string|null, provider: ?string, latency_ms: float, status: ?int}
     */
    private function failure(string $key, array $job, string $message, float $start, ?int $status = null): array
    {
        $providerRaw = $job['provider'] ?? null;
        return [
            'success' => false,
            'value' => [],
            'error' => $message,
            'provider' => is_string($providerRaw) ? $providerRaw : null,
            'latency_ms' => (microtime(true) - $start) * 1000.0,
            'status' => $status,
        ];
    }
}
