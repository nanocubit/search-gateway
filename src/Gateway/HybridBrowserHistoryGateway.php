<?php

declare(strict_types=1);

namespace SearchGateway\Gateway;

use SearchGateway\Contract\SearchGatewayException;

final class HybridBrowserHistoryGateway extends AbstractSearchGateway
{
    private const DEFAULT_BASE_URL = 'http://127.0.0.1:5000';

    public function __construct(
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly string $authToken = 'ai-agent-hybrid-token-2026',
        private readonly int $timeout = 5,
    ) {
    }

    public function searchWeb(string $query, array $options = []): array
    {
        $body = ['query' => $query, 'limit' => $options['limit'] ?? 10];
        if (isset($options['platform'])) {
            $body['platform'] = $options['platform'];
        }

        $response = $this->apiPost('/search/similar', $body);

        if (($response['status'] ?? '') !== 'success') {
            $errorMessage = $response['message'] ?? null;
            throw new SearchGatewayException(
                is_string($errorMessage) ? $errorMessage : 'HybridBrowserHistory API error',
                0,
                null,
                $this->providerName()
            );
        }

        $results = [];
        $rawResults = $response['results'] ?? [];

        if (!is_array($rawResults)) {
            return $results;
        }

        foreach ($rawResults as $item) {
            if (!is_array($item)) {
                continue;
            }

            $content = is_string($item['content'] ?? null) ? $item['content'] : '';
            $messageId = is_string($item['message_id'] ?? null) ? $item['message_id'] : '';

            $results[] = [
                'type' => 'history',
                'title' => mb_substr($content, 0, 100),
                'url' => 'internal://history/' . $messageId,
                'passage' => $content,
                'score' => (float) ($item['score'] ?? 0.0),
                'platform' => is_string($item['platform'] ?? null) ? $item['platform'] : '',
                'role' => is_string($item['role'] ?? null) ? $item['role'] : '',
                'timestamp' => is_string($item['timestamp'] ?? null) ? $item['timestamp'] : '',
                'graph_context' => $item['graph_context'] ?? null,
            ];
        }

        return $results;
    }

    public function searchNews(string $query, array $options = []): array
    {
        return [];
    }

    public function searchImages(string $query, array $options = []): array
    {
        return [];
    }

    public function llmContext(string $query, array $options = []): array
    {
        try {
            $results = $this->searchWeb($query, $options);
        } catch (SearchGatewayException $e) {
            throw $e;
        }

        return array_values(array_map(
            fn (array $r): array => [
                'url' => is_string($r['url'] ?? null) ? $r['url'] : '',
                'title' => is_string($r['title'] ?? null) ? $r['title'] : '',
                'domain' => is_string($r['platform'] ?? null) ? $r['platform'] : 'browser-history',
                'passage' => is_string($r['passage'] ?? null) ? $r['passage'] : '',
                'score' => is_numeric($r['score'] ?? null) ? (float) $r['score'] : 0.0,
            ],
            $results
        ));
    }

    public function wordstat(string $query, array $options = []): array
    {
        return [];
    }

    public function providerName(): string
    {
        return 'hybrid-browser-history';
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function apiPost(string $endpoint, array $body): array
    {
        $ch = curl_init($this->baseUrl . $endpoint);
        if ($ch === false) {
            throw new SearchGatewayException('Failed to initialise cURL', 0, null, $this->providerName());
        }

        $json = json_encode($body, JSON_THROW_ON_ERROR);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->authToken,
            ],
            CURLOPT_POSTFIELDS => $json,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false || $responseBody === '') {
            throw new SearchGatewayException(
                is_string($curlError) && $curlError !== '' ? $curlError : 'Empty response from hybrid browser history API',
                $httpCode,
                null,
                $this->providerName()
            );
        }

        if ($httpCode >= 400) {
            throw new SearchGatewayException(
                "HTTP $httpCode from hybrid browser history API",
                $httpCode,
                null,
                $this->providerName()
            );
        }

        /** @var string $responseBody */
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new SearchGatewayException('Invalid JSON response', 0, null, $this->providerName());
        }

        return $decoded;
    }
}
