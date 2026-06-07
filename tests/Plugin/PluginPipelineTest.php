<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use SearchGateway\Plugin\LoggingPlugin;
use SearchGateway\Plugin\MetricsPlugin;
use SearchGateway\Plugin\PluginContext;
use SearchGateway\Plugin\PluginInterface;
use SearchGateway\Plugin\PluginPipeline;
use SearchGateway\Plugin\CacheKeyPlugin;
use SearchGateway\Request\SearchRequest;
use SearchGateway\Request\SearchResponse;

final class PluginPipelineTest extends TestCase
{
    public function testAddAndCountTrackPlugins(): void
    {
        $p = new PluginPipeline();
        $p->add(new LoggingPlugin());
        $p->add(new MetricsPlugin());

        self::assertSame(2, $p->count());
        self::assertCount(2, $p->all());
    }

    public function testWithPluginReturnsNewInstance(): void
    {
        $a = new PluginPipeline();
        $b = $a->withPlugin(new LoggingPlugin());

        self::assertNotSame($a, $b);
        self::assertSame(0, $a->count());
        self::assertSame(1, $b->count());
    }

    public function testWithPluginsFiltersInvalid(): void
    {
        $mixed = [
            new LoggingPlugin(),
            'not a plugin',
            null,
            new MetricsPlugin(),
        ];
        $plugins = array_values(array_filter($mixed, static fn ($x): bool => $x instanceof PluginInterface));
        $p = (new PluginPipeline())->withPlugins($plugins);

        self::assertSame(2, $p->count());
    }

    public function testClearReturnsEmptyPipeline(): void
    {
        $p = (new PluginPipeline())->withPlugin(new LoggingPlugin())->clear();
        self::assertSame(0, $p->count());
    }

    public function testRunBeforeChainsPluginsInOrder(): void
    {
        $a = $this->makeAppender('A');
        $b = $this->makeAppender('B');
        $p = (new PluginPipeline())->add($a)->add($b);

        $req = new SearchRequest(query: 'q');
        $out = $p->runBefore($req, PluginContext::empty());

        $tag = $out->userContext['tag'] ?? null;
        self::assertIsString($tag);
        self::assertSame('AB', $tag);
    }

    public function testRunAfterChainsPluginsInOrder(): void
    {
        $a = $this->makeMetaAppender('A');
        $b = $this->makeMetaAppender('B');
        $p = (new PluginPipeline())->add($a)->add($b);

        $resp = SearchResponse::ok('searchWeb', 'r', []);
        $out = $p->runAfter($resp, PluginContext::empty());

        self::assertSame('AB', $out->meta['tag']);
    }

    public function testRunAfterReversedAppliesInReverseOrder(): void
    {
        $a = $this->makeMetaAppender('A');
        $b = $this->makeMetaAppender('B');
        $p = (new PluginPipeline())->add($a)->add($b);

        $resp = SearchResponse::ok('searchWeb', 'r', []);
        $out = $p->runAfterReversed($resp, PluginContext::empty());

        self::assertSame('BA', $out->meta['tag']);
    }

    public function testEmptyPipelineReturnsRequestAndResponseUnchanged(): void
    {
        $p = new PluginPipeline();
        $req = new SearchRequest(query: 'q');
        $resp = SearchResponse::ok('searchWeb', 'r', []);

        self::assertSame($req, $p->runBefore($req, PluginContext::empty()));
        self::assertSame($resp, $p->runAfter($resp, PluginContext::empty()));
    }

    public function testCacheKeyPluginAddsKeyToRequestAndMetaOnResponse(): void
    {
        $cache = $this->createMock(\SearchGateway\Infrastructure\CacheInterface::class);
        $cache->expects(self::once())
            ->method('set')
            ->with(self::stringStartsWith('sgw:route:v1.web:'), self::isType('array'), 60);

        $ctx = PluginContext::empty()->withCache($cache);
        $plugin = new CacheKeyPlugin();

        $req = new SearchRequest(query: 'php', routeName: 'v1.web');
        $next = $plugin->beforeSearch($req, $ctx);
        self::assertArrayHasKey(CacheKeyPlugin::META_KEY, $next->userContext);

        $resp = SearchResponse::ok('searchWeb', 'v1.web', [], ['cache_ttl' => 60]);
        $out = $plugin->afterSearch($resp, $ctx);
        $key = $next->userContext[CacheKeyPlugin::META_KEY];
        self::assertIsString($key);
        self::assertSame($key, $out->meta['cache_key']);
    }

    public function testCacheKeyPluginSkipsCacheForFailedResponse(): void
    {
        $cache = $this->createMock(\SearchGateway\Infrastructure\CacheInterface::class);
        $cache->expects(self::never())->method('set');
        $ctx = PluginContext::empty()->withCache($cache);

        $req = new SearchRequest(query: 'q', routeName: 'r');
        (new CacheKeyPlugin())->beforeSearch($req, $ctx);
        $resp = SearchResponse::error('searchWeb', 'r', 'x', 500);

        $out = (new CacheKeyPlugin())->afterSearch($resp, $ctx);
        self::assertArrayNotHasKey('cache_key', $out->meta);
    }

    public function testCacheKeyPluginSkipsCacheWhenApiKeyPresent(): void
    {
        $cache = $this->createMock(\SearchGateway\Infrastructure\CacheInterface::class);
        $cache->expects(self::never())->method('set');
        $ctx = PluginContext::empty()->withCache($cache);

        $resp = SearchResponse::ok('searchWeb', 'r', [], ['apiKeyId' => 'k-1', 'cache_ttl' => 60]);

        (new CacheKeyPlugin())->afterSearch($resp, $ctx);
    }

    private function makeAppender(string $tag): \SearchGateway\Plugin\PluginInterface
    {
        return new class($tag) implements \SearchGateway\Plugin\PluginInterface {
            public function __construct(private readonly string $tag)
            {
            }

            public function name(): string
            {
                return 'appender-' . $this->tag;
            }

            public function beforeSearch(SearchRequest $request, PluginContext $context): SearchRequest
            {
                $current = isset($request->userContext['tag']) && is_string($request->userContext['tag'])
                    ? $request->userContext['tag']
                    : '';
                return $request->withUserContext('tag', $current . $this->tag);
            }

            public function afterSearch(SearchResponse $response, PluginContext $context): SearchResponse
            {
                $current = isset($response->meta['tag']) && is_string($response->meta['tag'])
                    ? $response->meta['tag']
                    : '';
                return $response->withMetaValue('tag', $current . $this->tag);
            }
        };
    }

    private function makeMetaAppender(string $tag): \SearchGateway\Plugin\PluginInterface
    {
        return $this->makeAppender($tag);
    }
}
