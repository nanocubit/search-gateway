<?php

declare(strict_types=1);

namespace SearchGateway\Gateway;

use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Infrastructure\NormalizerTrait;

/**
 * Abstract base providing shared normalisation and utility.
 */
abstract class AbstractSearchGateway implements SearchGatewayInterface
{
    use NormalizerTrait;

    /**
     * @param array<string, mixed> $options
     */
    public function searchGen(string $query, array $options = []): GenerativeSearchResultDTO
    {
        $ctx = $this->llmContext($query, $options);
        return new GenerativeSearchResultDTO(
            answer: '',
            sources: $ctx,
            meta: ['provider' => $this->providerName(), 'synthesised' => true]
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>|array<string, mixed>
     */
    public function wordstat(string $query, array $options = []): array
    {
        return [];
    }

    public function providerName(): string
    {
        return 'unknown';
    }
}
