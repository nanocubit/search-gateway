<?php

declare(strict_types=1);

namespace SearchGateway\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SearchGateway\ApiKey\ApiKeyManager;
use SearchGateway\Http\JsonResponse;

final class AuthMiddleware implements MiddlewareInterface
{
    public const ATTR_API_KEY_ID = 'sgw.apiKeyId';
    public const ATTR_API_KEY = 'sgw.apiKey';

    public function __construct(
        private readonly ApiKeyManager $keys,
        private readonly ?\Closure $requiredScopesExtractor = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $rawKey = $this->extractBearer($request);
        if ($rawKey === null) {
            return $this->unauthorized('Missing Authorization: Bearer header');
        }

        $scopes = $this->extractScopes($request);
        try {
            $apiKey = $this->keys->authenticate($rawKey, $scopes);
        } catch (\SearchGateway\Contract\SearchGatewayException $e) {
            return $this->unauthorized($e->getMessage(), $e->getCode() === 403 ? 403 : 401);
        }

        $request = $request
            ->withAttribute(self::ATTR_API_KEY_ID, $apiKey->id())
            ->withAttribute(self::ATTR_API_KEY, $apiKey);

        return $handler->handle($request);
    }

    private function extractBearer(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if ($header === '') {
            return null;
        }
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return null;
        }
        return trim($m[1]);
    }

    /**
     * @return list<string>
     */
    private function extractScopes(ServerRequestInterface $request): array
    {
        $extractor = $this->requiredScopesExtractor;
        if ($extractor === null) {
            return [];
        }
        $value = $extractor($request);
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter($value, 'is_string'));
    }

    private function unauthorized(string $message, int $status = 401): ResponseInterface
    {
        return JsonResponse::create(new \Nyholm\Psr7\Response(), $status, [
            'ok' => false,
            'error' => $message,
        ]);
    }
}
