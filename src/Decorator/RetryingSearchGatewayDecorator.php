<?php

declare(strict_types=1);

namespace SearchGateway\Decorator;

use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Contract\SearchGatewayException;

/**
 * Exponential-backoff retry decorator.
 */
final class RetryingSearchGatewayDecorator implements SearchGatewayInterface
{
    public function __construct(
        private SearchGatewayInterface $inner,
        private int $retries = 2,
        private int $delayMs = 150
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchWeb(string $query, array $options = []): array
    {
        return $this->retry(fn() => $this->inner->searchWeb($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchNews(string $query, array $options = []): array
    {
        return $this->retry(fn() => $this->inner->searchNews($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchImages(string $query, array $options = []): array
    {
        return $this->retry(fn() => $this->inner->searchImages($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function searchGen(string $query, array $options = []): GenerativeSearchResultDTO
    {
        return $this->retry(fn() => $this->inner->searchGen($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>|array<string, mixed>
     */
    public function wordstat(string $query, array $options = []): array
    {
        return $this->retry(fn() => $this->inner->wordstat($query, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array{url:string, title:string, domain:string, passage:string, score:float}>
     */
    public function llmContext(string $query, array $options = []): array
    {
        return $this->retry(fn() => $this->inner->llmContext($query, $options));
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function retry(callable $fn): mixed
    {
        $attempts = $this->retries + 1;
        $last = null;

        for ($i = 0; $i < $attempts; $i++) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                $last = $e;
                if ($i < $attempts - 1) {
                    usleep($this->delayMs * 1000 * (2 ** $i));
                }
            }
        }

        if ($last === null) {
            throw new SearchGatewayException('Retry failed with no exception captured');
        }

        throw new SearchGatewayException(
            sprintf('Failed after %d attempts: %s', $attempts, $last->getMessage()),
            (int) $last->getCode(),
            $last
        );
    }
}
