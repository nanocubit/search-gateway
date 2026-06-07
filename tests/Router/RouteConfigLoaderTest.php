<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Router;

use PHPUnit\Framework\TestCase;
use SearchGateway\Contract\SearchGatewayException;
use SearchGateway\Router\Route;
use SearchGateway\Router\RouteConfigLoader;
use SearchGateway\Router\RoutePresets;

final class RouteConfigLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sgw-routes-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            unlink((string) $f);
        }
        rmdir($this->tmpDir);
    }

    public function testLoadFromArrayBuildsRoutes(): void
    {
        $loader = new RouteConfigLoader();
        $routes = $loader->loadFromArray([
            [
                'name' => 'v1.test',
                'method' => 'post',
                'path' => '/v1/test',
                'action' => Route::ACTION_SEARCH_WEB,
                'requiredScopes' => ['search:test'],
            ],
        ]);

        self::assertCount(1, $routes);
        self::assertSame('v1.test', $routes[0]->name);
        self::assertSame('POST', $routes[0]->method);
        self::assertSame('/v1/test', $routes[0]->path);
        self::assertSame(['search:test'], $routes[0]->requiredScopes);
    }

    public function testLoadFromJsonFile(): void
    {
        $file = $this->tmpDir . '/routes.json';
        file_put_contents($file, (string) json_encode([
            'routes' => [
                ['name' => 'a', 'method' => 'GET', 'path' => '/a', 'action' => Route::ACTION_SEARCH_WEB],
                ['name' => 'b', 'method' => 'POST', 'path' => '/b', 'action' => Route::ACTION_SEARCH_GEN, 'rateLimit' => ['limit' => 5, 'window' => 10]],
            ],
        ]));

        $loader = new RouteConfigLoader();
        $routes = $loader->loadFromFile($file);

        self::assertCount(2, $routes);
        self::assertSame('a', $routes[0]->name);
        self::assertSame('GET', $routes[0]->method);
        self::assertSame('b', $routes[1]->name);
        self::assertSame(['limit' => 5, 'window' => 10], $routes[1]->rateLimit);
    }

    public function testLoadFromPhpFile(): void
    {
        $file = $this->tmpDir . '/routes.php';
        file_put_contents($file, '<?php return ["routes" => [[' .
            '"name" => "x", "method" => "GET", "path" => "/x", "action" => "searchWeb"' .
        ']]];');

        $loader = new RouteConfigLoader();
        $routes = $loader->loadFromFile($file);

        self::assertCount(1, $routes);
        self::assertSame('x', $routes[0]->name);
    }

    public function testLoadFromYamlWithoutParserThrows(): void
    {
        $file = $this->tmpDir . '/routes.yaml';
        file_put_contents($file, "routes:\n  - name: y\n    method: GET\n    path: /y\n    action: searchWeb\n");

        $loader = new RouteConfigLoader();
        $this->expectException(SearchGatewayException::class);
        $this->expectExceptionMessage('YAML parsing requires symfony/yaml');
        $loader->loadFromFile($file);
    }

    public function testLoadFromYamlWithCustomParser(): void
    {
        $file = $this->tmpDir . '/routes.yaml';
        file_put_contents($file, "routes:\n  - name: z\n    method: POST\n    path: /z\n    action: searchWeb\n");

        $parser = new class () implements \SearchGateway\Router\YamlParserInterface {
            public function parse(string $yaml): mixed
            {
                return ['routes' => [['name' => 'z', 'method' => 'POST', 'path' => '/z', 'action' => 'searchWeb']]];
            }
        };

        $loader = new RouteConfigLoader($parser);
        $routes = $loader->loadFromFile($file);

        self::assertCount(1, $routes);
        self::assertSame('z', $routes[0]->name);
    }

    public function testUnsupportedExtensionThrows(): void
    {
        $file = $this->tmpDir . '/routes.txt';
        file_put_contents($file, 'noop');

        $loader = new RouteConfigLoader();
        $this->expectException(SearchGatewayException::class);
        $this->expectExceptionMessage('Unsupported route config extension');
        $loader->loadFromFile($file);
    }

    public function testMissingFileThrows(): void
    {
        $loader = new RouteConfigLoader();
        $this->expectException(SearchGatewayException::class);
        $loader->loadFromFile($this->tmpDir . '/missing.json');
    }

    public function testMissingRequiredFieldThrows(): void
    {
        $loader = new RouteConfigLoader();
        $this->expectException(SearchGatewayException::class);
        $this->expectExceptionMessage('Route config missing "name"');
        $loader->loadFromArray([['method' => 'GET', 'path' => '/x', 'action' => 'searchWeb']]);
    }

    public function testInvalidJsonThrows(): void
    {
        $file = $this->tmpDir . '/bad.json';
        file_put_contents($file, '{ not json');

        $loader = new RouteConfigLoader();
        $this->expectException(SearchGatewayException::class);
        $this->expectExceptionMessage('Invalid JSON');
        $loader->loadFromFile($file);
    }

    public function testWebSearchPresetExposesThreeRoutes(): void
    {
        $routes = RoutePresets::webSearch();
        self::assertCount(3, $routes);
        self::assertSame('v1.search.web', $routes[0]->name);
        self::assertSame('v1.search.news', $routes[1]->name);
        self::assertSame('v1.search.images', $routes[2]->name);
    }

    public function testGenerativePresetExposesThreeRoutes(): void
    {
        $routes = RoutePresets::generative();
        self::assertCount(3, $routes);
        self::assertContains(Route::ACTION_SEARCH_GEN, [$routes[0]->action]);
        self::assertContains(Route::ACTION_LLM_CONTEXT, [$routes[1]->action]);
        self::assertContains(Route::ACTION_HYBRID, [$routes[2]->action]);
    }

    public function testStreamingPresetUsesStreamAction(): void
    {
        $routes = RoutePresets::streaming();
        self::assertGreaterThanOrEqual(2, count($routes));
        foreach ($routes as $r) {
            self::assertSame('stream', $r->action);
        }
    }

    public function testAllPresetMergesEveryGroup(): void
    {
        $all = RoutePresets::all();
        $web = count(RoutePresets::webSearch());
        $gen = count(RoutePresets::generative());
        $ana = count(RoutePresets::analytics());
        $str = count(RoutePresets::streaming());
        self::assertSame($web + $gen + $ana + $str, count($all));
    }

    public function testConfigFieldIsCarriedOver(): void
    {
        $loader = new RouteConfigLoader();
        $routes = $loader->loadFromArray([[
            'name' => 'with-cfg',
            'method' => 'POST',
            'path' => '/cfg',
            'action' => Route::ACTION_SEARCH_WEB,
            'config' => ['stream_generator' => 'fn() => []'],
        ]]);
        self::assertSame(['stream_generator' => 'fn() => []'], $routes[0]->config);
    }
}
