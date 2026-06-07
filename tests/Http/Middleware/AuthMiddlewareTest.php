<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Http\Middleware;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use SearchGateway\ApiKey\ApiKeyManager;
use SearchGateway\ApiKey\InMemoryApiKeyStore;
use SearchGateway\Http\Middleware\AuthMiddleware;

final class AuthMiddlewareTest extends TestCase
{
    private ApiKeyManager $keys;
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->keys = new ApiKeyManager(new InMemoryApiKeyStore(), clock: static fn (): int => 1000);
        $this->factory = new Psr17Factory();
    }

    public function testReturns401WhenHeaderMissing(): void
    {
        $mw = new AuthMiddleware($this->keys);
        $request = $this->factory->createServerRequest('GET', '/v1/search/web');

        $response = $mw->process($request, $this->okHandler());

        self::assertSame(401, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['ok']);
        $err = $body['error'] ?? '';
        self::assertIsString($err);
        self::assertStringContainsString('Missing', $err);
    }

    public function testReturns401WhenHeaderMalformed(): void
    {
        $mw = new AuthMiddleware($this->keys);
        $request = $this->factory->createServerRequest('GET', '/v1/search/web')
            ->withHeader('Authorization', 'Basic abc');

        $response = $mw->process($request, $this->okHandler());
        self::assertSame(401, $response->getStatusCode());
    }

    public function testReturns401OnUnknownKey(): void
    {
        $mw = new AuthMiddleware($this->keys);
        $request = $this->factory->createServerRequest('GET', '/v1/search/web')
            ->withHeader('Authorization', 'Bearer sgw_unknown');

        $response = $mw->process($request, $this->okHandler());
        self::assertSame(401, $response->getStatusCode());
    }

    public function testReturns403WhenScopeMissing(): void
    {
        [$raw] = $this->keys->create('o', ['search:web']);
        $mw = new AuthMiddleware($this->keys, requiredScopesExtractor: static fn (): array => ['admin:routes']);
        $request = $this->factory->createServerRequest('GET', '/v1/search/web')
            ->withHeader('Authorization', 'Bearer ' . $raw);

        $response = $mw->process($request, $this->okHandler());
        self::assertSame(403, $response->getStatusCode());
    }

    public function testAttachesApiKeyAttributesOnSuccess(): void
    {
        [$raw, $key] = $this->keys->create('owner-1', ['search:web']);
        $mw = new AuthMiddleware($this->keys);
        $request = $this->factory->createServerRequest('GET', '/v1/search/web')
            ->withHeader('Authorization', 'Bearer ' . $raw);

        $handler = new class implements RequestHandlerInterface {
            public ?\Psr\Http\Message\ServerRequestInterface $seen = null;

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->seen = $request;
                return new Response();
            }
        };
        $response = $mw->process($request, $handler);
        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($handler->seen);
        self::assertSame($key->id(), $handler->seen->getAttribute(AuthMiddleware::ATTR_API_KEY_ID));
        self::assertSame($key, $handler->seen->getAttribute(AuthMiddleware::ATTR_API_KEY));
    }

    private function okHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return new Response();
            }
        };
    }
}
