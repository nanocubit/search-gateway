<?php

declare(strict_types=1);

namespace SearchGateway\Infrastructure;

/**
 * Abstraction for SSE / chunked generative search streaming.
 */
interface StreamingResponseInterface
{
    /**
     * Yield chunks as they arrive from the provider.
     *
     * @return \Generator<string>
     */
    public function stream(): \Generator;

    /**
     * Final assembled answer after stream completes.
     */
    public function getAnswer(): string;

    /**
     * Sources collected during streaming.
     *
     * @return list<array<string, mixed>>
     */
    public function getSources(): array;
}
