<?php

declare(strict_types=1);

namespace SearchGateway\Decorator;

use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Contract\SearchGatewayException;

/**
 * Circuit-breaker / fallback decorator.
 * On failure of the primary gateway, tries a chain of fallback gateways.
 */
final class FallbackSearchGatewayDecorator implements SearchGatewayInterface
{
    /**
     * @param list<SearchGatewayInterface> $fallbacks
     */
    public function __construct(
        private SearchGatewayInterface $primary,
        private array $fallbacks
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchWeb(string $query, array $options = []): array
    {
        $result = $this->dispatch('searchWeb', $query, $options);
        return is_array($result) ? array_values(array_filter($result, 'is_array')) : [];
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchNews(string $query, array $options = []): array
    {
        $result = $this->dispatch('searchNews', $query, $options);
        return is_array($result) ? array_values(array_filter($result, 'is_array')) : [];
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function searchImages(string $query, array $options = []): array
    {
        $result = $this->dispatch('searchImages', $query, $options);
        return is_array($result) ? array_values(array_filter($result, 'is_array')) : [];
    }

    /**
     * @param array<string, mixed> $options
     */
    public function searchGen(string $query, array $options = []): GenerativeSearchResultDTO
    {
        $result = $this->dispatch('searchGen', $query, $options);
        if (!$result instanceof GenerativeSearchResultDTO) {
            throw new SearchGatewayException('Fallback chain did not return a GenerativeSearchResultDTO');
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>|array<string, mixed>
     */
    public function wordstat(string $query, array $options = []): array
    {
        $result = $this->dispatch('wordstat', $query, $options);
        return is_array($result) ? $result : [];
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function llmContext(string $query, array $options = []): array
    {
        $result = $this->dispatch('llmContext', $query, $options);
        if (!is_array($result)) {
            return [];
        }
        $out = [];
        foreach ($result as $doc) {
            if (is_array($doc)) {
                /** @var array<string, mixed> $doc */
                $out[] = $doc;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function dispatch(string $method, string $query, array $options): mixed
    {
        $chain = array_merge([$this->primary], $this->fallbacks);
        $last = null;
        $result = null;
        $found = false;

        foreach ($chain as $gateway) {
            try {
                $result = match ($method) {
                    'searchWeb' => $gateway->searchWeb($query, $options),
                    'searchNews' => $gateway->searchNews($query, $options),
                    'searchImages' => $gateway->searchImages($query, $options),
                    'searchGen' => $gateway->searchGen($query, $options),
                    'wordstat' => $gateway->wordstat($query, $options),
                    'llmContext' => $gateway->llmContext($query, $options),
                    default => throw new SearchGatewayException("Unknown fallback method: {$method}"),
                };
                $found = true;
                break;
            } catch (\Throwable $e) {
                $last = $e;
            }
        }

        if ($found) {
            return $result;
        }

        if ($last !== null) {
            throw new SearchGatewayException(
                'All fallback gateways failed. Last: ' . $last->getMessage(),
                (int) $last->getCode(),
                $last
            );
        }
        throw new SearchGatewayException('No gateways available in fallback chain');
    }
}
