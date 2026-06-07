<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use SearchGateway\Infrastructure\LoggerInterface;
use SearchGateway\Plugin\LoggingPlugin;
use SearchGateway\Plugin\PluginContext;
use SearchGateway\Request\SearchRequest;
use SearchGateway\Request\SearchResponse;

final class LoggingPluginTest extends TestCase
{
    public function testNameIsLogging(): void
    {
        self::assertSame('logging', (new LoggingPlugin())->name());
    }

    public function testNoLoggerDoesNothing(): void
    {
        $plugin = new LoggingPlugin();
        $req = new SearchRequest(query: 'q', routeName: 'r');
        $resp = SearchResponse::ok('searchWeb', 'r', []);

        self::assertSame($req, $plugin->beforeSearch($req, PluginContext::empty()));
        self::assertSame($resp, $plugin->afterSearch($resp, PluginContext::empty()));
    }

    public function testContextLoggerWinsOverConstructorLogger(): void
    {
        $constructor = $this->createMock(LoggerInterface::class);
        $constructor->expects(self::never())->method('info');
        $context = $this->createMock(LoggerInterface::class);
        $context->expects(self::once())
            ->method('info')
            ->with(self::stringContains('start route=r query=q provider=auto stream=no'));

        $plugin = new LoggingPlugin($constructor);
        $ctx = PluginContext::empty()->withLogger($context);

        $plugin->beforeSearch(new SearchRequest(query: 'q', routeName: 'r'), $ctx);
    }

    public function testLongQueryIsShortened(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(self::logicalAnd(
                self::stringContains('query='),
                self::stringContains('...'),
            ));

        $plugin = new LoggingPlugin($logger);
        $longQuery = str_repeat('x', 200);
        $plugin->beforeSearch(new SearchRequest(query: $longQuery, routeName: 'r'), PluginContext::empty());
    }

    public function testAfterLogsLatencyFromMeta(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(self::stringContains('latency_ms=12.5'));

        $plugin = new LoggingPlugin($logger);
        $resp = SearchResponse::ok('searchWeb', 'r', [], ['latency_ms' => 12.5]);

        $plugin->afterSearch($resp, PluginContext::empty());
    }

    public function testCustomLevelRoutesToCorrespondingMethod(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('debug')->with(self::stringContains('start'));
        $logger->expects(self::never())->method('info');

        $plugin = new LoggingPlugin(level: 'debug');
        $plugin->beforeSearch(new SearchRequest(query: 'q', routeName: 'r'), PluginContext::empty()->withLogger($logger));
    }

    public function testAfterLogsStatusAndOkFlag(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(self::logicalAnd(
                self::stringContains('status=500'),
                self::stringContains('ok=no'),
            ));

        $plugin = new LoggingPlugin();
        $plugin->afterSearch(SearchResponse::error('searchWeb', 'r', 'x', 500), PluginContext::empty()->withLogger($logger));
    }
}
