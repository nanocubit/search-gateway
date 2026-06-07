<?php

declare(strict_types=1);

namespace SearchGateway\Graph;

use SearchGateway\Infrastructure\LLMClientInterface;

/**
 * Извлекает entities и relations из текста для построения knowledge graph.
 * Идея из RAGFlow: структурированный RAG через граф знаний.
 */
final class KnowledgeGraphExtractor
{
    public function __construct(private LLMClientInterface $llm)
    {
    }

    /**
     * Извлечь граф из текста.
     *
     * @return array{entities: list<array{name: string, type: string}>, relations: list<array{from: string, to: string, type: string}>}
     */
    public function extract(string $text): array
    {
        $prompt = <<<PROMPT
Extract entities and relations from the text below.
Output JSON only, no markdown, no explanation.

Format:
{
  "entities": [{"name": "...", "type": "PERSON|ORG|LOCATION|CONCEPT|TECH"}],
  "relations": [{"from": "...", "to": "...", "type": "..."}]
}

Text:
{$text}
PROMPT;

        $raw = $this->llm->generate($prompt);

        $cleaned = preg_replace('/^```json\s*|\s*```$/m', '', $raw);
        $clean = is_string($cleaned) ? trim($cleaned) : trim($raw);
        $decoded = json_decode($clean, true);

        $entities = [];
        $relations = [];
        if (is_array($decoded)) {
            $rawEntities = $decoded['entities'] ?? [];
            if (is_array($rawEntities)) {
                foreach ($rawEntities as $e) {
                    if (is_array($e) && is_scalar($e['name'] ?? null) && is_scalar($e['type'] ?? null)) {
                        $entities[] = ['name' => (string) $e['name'], 'type' => (string) $e['type']];
                    }
                }
            }
            $rawRelations = $decoded['relations'] ?? [];
            if (is_array($rawRelations)) {
                foreach ($rawRelations as $r) {
                    if (
                        is_array($r)
                        && is_scalar($r['from'] ?? null)
                        && is_scalar($r['to'] ?? null)
                        && is_scalar($r['type'] ?? null)
                    ) {
                        $relations[] = [
                            'from' => (string) $r['from'],
                            'to' => (string) $r['to'],
                            'type' => (string) $r['type'],
                        ];
                    }
                }
            }
        }

        return [
            'entities' => $entities,
            'relations' => $relations,
        ];
    }

    /**
     * Обогатить query через граф: найти связанные entities.
     *
     * @param list<array{name: string, type: string}> $entities
     * @return list<string>
     */
    public function expandQuery(string $query, array $entities): array
    {
        $related = [];
        foreach ($entities as $entity) {
            if (str_contains(strtolower($query), strtolower($entity['name']))) {
                $related[] = $entity['name'];
            }
        }
        return array_unique($related);
    }
}
