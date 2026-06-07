<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Portal;

use PHPUnit\Framework\TestCase;
use SearchGateway\Portal\OpenApiGenerator;
use SearchGateway\Router\InMemoryRouteRegistry;
use SearchGateway\Router\Route;

final class OpenApiGeneratorTest extends TestCase
{
    private InMemoryRouteRegistry $registry;
    private OpenApiGenerator $generator;

    protected function setUp(): void
    {
        $this->registry = new InMemoryRouteRegistry();
        $this->generator = new OpenApiGenerator($this->registry);
    }

    /**
     * @return array{
     *     openapi: string,
     *     info: array{title: string, version: string, description: string},
     *     servers: list<array{url: string, description: string}>,
     *     paths: array<string, array<string, array<string, mixed>>>,
     *     components: array{
     *         securitySchemes: array<string, array<string, string>>,
     *         schemas: array<string, array<string, mixed>>
     *     },
     *     security: list<array<string, list<string>>>
     * }
     */
    private function spec(): array
    {
        /** @var array{openapi: string, info: array{title: string, version: string, description: string}, servers: list<array{url: string, description: string}>, paths: array<string, array<string, array<string, mixed>>>, components: array{securitySchemes: array<string, array<string, string>>, schemas: array<string, array<string, mixed>>}, security: list<array<string, list<string>>>} $s */
        $s = $this->generator->generate();
        return $s;
    }

    public function testGeneratesValidOpenApiHeader(): void
    {
        $spec = $this->spec();

        self::assertSame('3.0.3', $spec['openapi']);
        self::assertArrayHasKey('info', $spec);
        self::assertArrayHasKey('servers', $spec);
        self::assertArrayHasKey('paths', $spec);
        self::assertArrayHasKey('components', $spec);
    }

    public function testIncludesSecuritySchemeAndGlobalSecurity(): void
    {
        $spec = $this->spec();

        self::assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes']);
        self::assertSame('http', $spec['components']['securitySchemes']['bearerAuth']['type']);
        self::assertSame('bearer', $spec['components']['securitySchemes']['bearerAuth']['scheme']);
        self::assertSame([['bearerAuth' => []]], $spec['security']);
    }

    public function testIncludesSchemas(): void
    {
        $spec = $this->spec();
        $schemas = $spec['components']['schemas'];
        self::assertIsArray($schemas);

        self::assertArrayHasKey('SearchRequest', $schemas);
        self::assertArrayHasKey('SearchResponse', $schemas);
        self::assertArrayHasKey('Error', $schemas);
        self::assertContains('query', (array) ($schemas['SearchRequest']['required'] ?? []));
    }

    public function testEmptyRegistryProducesEmptyPaths(): void
    {
        $spec = $this->spec();
        self::assertSame([], $spec['paths']);
    }

    public function testSingleRouteProducesPathWithLowercaseMethod(): void
    {
        $this->registry->register(new Route(
            name: 'v1.web',
            method: Route::METHOD_POST,
            path: '/v1/search/web',
            action: Route::ACTION_SEARCH_WEB,
        ));

        $spec = $this->spec();
        self::assertArrayHasKey('/v1/search/web', $spec['paths']);
        self::assertArrayHasKey('post', $spec['paths']['/v1/search/web']);
        self::assertSame('v1.web', $spec['paths']['/v1/search/web']['post']['operationId']);
        self::assertSame([Route::ACTION_SEARCH_WEB], $spec['paths']['/v1/search/web']['post']['tags']);
    }

    public function testPostRouteHasRequestBodySchema(): void
    {
        $this->registry->register(new Route(
            name: 'v1.gen',
            method: Route::METHOD_POST,
            path: '/v1/search/gen',
            action: Route::ACTION_SEARCH_GEN,
        ));

        $spec = $this->spec();
        $op = $spec['paths']['/v1/search/gen']['post'];
        self::assertIsArray($op);

        self::assertArrayHasKey('requestBody', $op);
        $body = $op['requestBody'];
        self::assertIsArray($body);
        self::assertTrue((bool) ($body['required'] ?? false));
        self::assertSame(
            ['$ref' => '#/components/schemas/SearchRequest'],
            $body['content']['application/json']['schema'] ?? null,
        );
    }

    public function testGetRouteHasNoRequestBody(): void
    {
        $this->registry->register(new Route(
            name: 'v1.list',
            method: Route::METHOD_GET,
            path: '/v1/list',
            action: Route::ACTION_SEARCH_WEB,
        ));

        $spec = $this->spec();
        self::assertArrayNotHasKey('requestBody', $spec['paths']['/v1/list']['get']);
    }

    public function testRouteScopesAndRateLimitAppearInDescription(): void
    {
        $this->registry->register(new Route(
            name: 'v1.web',
            method: Route::METHOD_POST,
            path: '/v1/search/web',
            action: Route::ACTION_SEARCH_WEB,
            requiredScopes: ['search:web'],
            rateLimit: ['limit' => 100, 'window' => 60],
        ));

        $spec = $this->spec();
        $desc = $spec['paths']['/v1/search/web']['post']['description'] ?? '';
        self::assertIsString($desc);

        self::assertStringContainsString('Required scopes: search:web', $desc);
        self::assertStringContainsString('Rate limit: 100 requests per 60 seconds', $desc);
    }

    public function testAllRouteActionsGenerateResponses(): void
    {
        $this->registry->register(new Route(
            name: 'v1.web',
            method: Route::METHOD_POST,
            path: '/v1/search/web',
            action: Route::ACTION_SEARCH_WEB,
        ));

        $spec = $this->spec();
        $responses = $spec['paths']['/v1/search/web']['post']['responses'] ?? [];
        self::assertIsArray($responses);

        foreach (['200', '400', '401', '403', '429', '500'] as $code) {
            self::assertArrayHasKey($code, $responses);
        }
    }

    public function testToJsonReturnsValidJsonString(): void
    {
        $this->registry->register(new Route(
            name: 'v1.web',
            method: Route::METHOD_POST,
            path: '/v1/search/web',
            action: Route::ACTION_SEARCH_WEB,
        ));

        $json = $this->generator->toJson();
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertSame('3.0.3', $decoded['openapi'] ?? null);
        $paths = $decoded['paths'] ?? [];
        self::assertIsArray($paths);
        self::assertArrayHasKey('/v1/search/web', $paths);
    }

    public function testMultipleRoutesOnSamePathAreGrouped(): void
    {
        $this->registry->register(new Route(
            name: 'v1.web.get',
            method: Route::METHOD_GET,
            path: '/v1/search/web',
            action: Route::ACTION_SEARCH_WEB,
        ));
        $this->registry->register(new Route(
            name: 'v1.web.post',
            method: Route::METHOD_POST,
            path: '/v1/search/web',
            action: Route::ACTION_SEARCH_WEB,
        ));

        $spec = $this->spec();
        $pathItem = $spec['paths']['/v1/search/web'] ?? [];
        self::assertIsArray($pathItem);

        self::assertArrayHasKey('get', $pathItem);
        self::assertArrayHasKey('post', $pathItem);
        self::assertSame('v1.web.get', $pathItem['get']['operationId'] ?? null);
        self::assertSame('v1.web.post', $pathItem['post']['operationId'] ?? null);
    }

    public function testCustomTitleAndVersionAreRespected(): void
    {
        $gen = new OpenApiGenerator($this->registry, title: 'My API', version: '2.5.0');
        $spec = $gen->generate();
        $info = $spec['info'] ?? [];
        self::assertIsArray($info);

        self::assertSame('My API', $info['title'] ?? null);
        self::assertSame('2.5.0', $info['version'] ?? null);
    }
}
