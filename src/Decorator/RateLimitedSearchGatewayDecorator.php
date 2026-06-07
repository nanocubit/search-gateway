<?php

declare(strict_types=1);

namespace SearchGateway\Decorator;

use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Contract\SearchGatewayException;
use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Infrastructure\RateLimiterInterface;

/**
 * Rate limiter decorator. Throws SearchGatewayException if limit exceeded.
 */
final class RateLimitedSearchGatewayDecorator implements SearchGatewayInterface
{
    public function __construct(
        private SearchGatewayInterface $inner,
        private RateLimiterInterface $limiter,
        private string $providerKey,
        private int $maxRequests = 60,
        private int $windowSeconds = 60
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchWeb(string $query, array $options = []): array
    {
        return $this->throttle(__FUNCTION__, fn() => $this->inner->searchWeb($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchNews(string $query, array $options = []): array
    {
        return $this->throttle(__FUNCTION__, fn() => $this->inner->searchNews($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchImages(string $query, array $options = []): array
    {
        return $this->throttle(__FUNCTION__, fn() => $this->inner->searchImages($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function searchGen(string $query, array $options = []): GenerativeSearchResultDTO
    {
        return $this->throttle(__FUNCTION__, fn() => $this->inner->searchGen($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>|array<string, mixed>
     */
    public function wordstat(string $query, array $options = []): array
    {
        return $this->throttle(__FUNCTION__, fn() => $this->inner->wordstat($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array{url:string, title:string, domain:string, passage:string, score:float}>
     */
    public function llmContext(string $query, array $options = []): array
    {
        return $this->throttle(__FUNCTION__, fn() => $this->inner->llmContext($query, $options));
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function throttle(string $method, callable $fn): mixed
    {
        $key = "{$this->providerKey}:{$method}";

        if (!$this->limiter->acquire($key, $this->maxRequests, $this->windowSeconds)) {
            $wait = $this->limiter->waitTime($key, $this->maxRequests, $this->windowSeconds);
            throw new SearchGatewayException(
                sprintf('Rate limit exceeded for %s. Retry after %.1f seconds.', $key, $wait),
                429
            );
        }

        return $fn();
    }
}
