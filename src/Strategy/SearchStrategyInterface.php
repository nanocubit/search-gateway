<?php

declare(strict_types=1);

namespace SearchGateway\Strategy;

use SearchGateway\Contract\SearchGatewayInterface;

/**
 * Strategy pattern for search execution.
 * Позволяет подменять алгоритм поиска без изменения клиента.
 */
interface SearchStrategyInterface
{
    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function execute(SearchGatewayInterface $gateway, string $query, array $options = []): array;
}
