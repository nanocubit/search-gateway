<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Support;

use SearchGateway\Infrastructure\LLMClientInterface;

/**
 * Configurable LLM mock for unit tests. Records every prompt and returns
 * pre-programmed responses in order.
 */
final class MockLLMClient implements LLMClientInterface
{
    /** @var list<string> */
    private array $responses;

    /** @var list<string> */
    public array $prompts = [];

    private int $cursor = 0;

    /**
     * @param list<string> $responses Responses to return, in order. If exhausted,
     *                                the last response is returned repeatedly.
     */
    public function __construct(array $responses = [''])
    {
        $this->responses = $responses === [] ? [''] : array_values($responses);
    }

    public function generate(string $prompt, array $options = []): string
    {
        $this->prompts[] = $prompt;
        $idx = $this->cursor;
        if ($idx >= count($this->responses)) {
            $idx = count($this->responses) - 1;
        }
        $this->cursor++;
        return $this->responses[$idx];
    }

    /**
     * @return list<string>
     */
    public function seenPrompts(): array
    {
        return $this->prompts;
    }
}
