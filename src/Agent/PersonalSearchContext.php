<?php

declare(strict_types=1);

namespace SearchGateway\Agent;

use SearchGateway\Tool\SearchTool;

/**
 * Comet / Atlas-style personal context manager.
 * Maintains user-specific search history and preferences to enrich future queries.
 */
final class PersonalSearchContext
{
    /** @var list<array{query:string, timestamp:int, results:list<array<string, mixed>>}> */
    private array $history = [];

    /** @var array<string, mixed> */
    private array $profile = [];

    public function __construct(
        private ?SearchTool $searchTool = null,
        private int $maxHistory = 20
    ) {
    }

    /**
     * Enrich a raw user query with historical context and profile hints.
     */
    public function enrich(string $query): string
    {
        $hints = [];

        if (isset($this->profile['location'])) {
            $hints[] = 'Location: ' . $this->profile['location'];
        }
        if (isset($this->profile['language'])) {
            $hints[] = 'Language: ' . $this->profile['language'];
        }

        $recent = array_slice($this->history, -3);
        if ($recent !== []) {
            $hints[] = 'Recent searches: ' . implode(', ', array_column($recent, 'query'));
        }

        if ($hints === []) {
            return $query;
        }

        return implode("
", $hints) . "

Current query: " . $query;
    }

    /**
     * Record a query and its results into personal history.
     *
     * @param list<array<string, mixed>> $results
     */
    public function record(string $query, array $results): void
    {
        $this->history[] = [
            'query' => $query,
            'timestamp' => time(),
            'results' => array_slice($results, 0, 5),
        ];

        if (count($this->history) > $this->maxHistory) {
            array_shift($this->history);
        }
    }

    /**
     * Set a user profile attribute.
     */
    public function setProfile(string $key, mixed $value): void
    {
        $this->profile[$key] = $value;
    }

    /**
     * Get full history (for inspection or export).
     *
     * @return list<array{query:string, timestamp:int, results:list<array<string, mixed>>}>
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    /**
     * Suggest a follow-up query based on recent history.
     */
    public function suggestFollowUp(): ?string
    {
        $last = end($this->history);
        if ($last === false || $this->searchTool === null) {
            return null;
        }

        // Simple heuristic: ask the LLM to suggest a follow-up.
        $ctx = $this->searchTool->context('Related questions to: ' . $last['query']);
        $first = $ctx[0] ?? null;
        return $first['title'] ?? null;
    }
}
