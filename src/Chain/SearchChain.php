<?php

declare(strict_types=1);

namespace SearchGateway\Chain;

use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Contract\SearchGatewayInterface;

/**
 * LangChain Expression Language (LCEL) для PHP.
 * Композиция поисковых операций как lazy pipeline.
 * Каждый step — callable(array): array, работающий с контекстом.
 *
 * Пример:
 *   $chain = SearchChain::from($gateway)
 *       ->pipe(fn($ctx) => [...$ctx, 'docs' => $ctx['gateway']->llmContext($ctx['query'])])
 *       ->pipe(fn($ctx) => [...$ctx, 'ranked' => $ranker->rank($ctx['docs'], $ctx['query'])])
 *       ->pipe(fn($ctx) => [...$ctx, 'answer' => $llm->generate($ctx['ranked'])]);
 */
final class SearchChain
{
    /** @var list<callable(array<string, mixed>): array<string, mixed>> */
    private array $steps = [];

    private function __construct(private SearchGatewayInterface $gateway)
    {
    }

    public static function from(SearchGatewayInterface $gateway): self
    {
        return new self($gateway);
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $fn
     */
    public function pipe(callable $fn): self
    {
        $this->steps[] = $fn;
        return $this;
    }

    /**
     * Выполнить цепочку с начальным контекстом.
     *
     * @param array<string, mixed> $initialContext
     * @return array<string, mixed>
     */
    public function invoke(array $initialContext): array
    {
        $ctx = array_merge($initialContext, ['gateway' => $this->gateway]);
        foreach ($this->steps as $step) {
            $ctx = $step($ctx);
        }
        return $ctx;
    }

    /**
     * Stream версия: yield промежуточные результаты.
     *
     * @param array<string, mixed> $initialContext
     * @return \Generator<int, array<string, mixed>, mixed, array<string, mixed>>
     */
    public function stream(array $initialContext): \Generator
    {
        $ctx = array_merge($initialContext, ['gateway' => $this->gateway]);
        foreach ($this->steps as $i => $step) {
            $ctx = $step($ctx);
            yield ["step_{$i}" => $ctx];
        }
        return $ctx;
    }
}
