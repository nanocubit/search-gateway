<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Router;

use PHPUnit\Framework\TestCase;
use SearchGateway\Router\PathMatcher;

final class PathMatcherTest extends TestCase
{
    public function testNormaliseAddsLeadingSlash(): void
    {
        self::assertSame('/foo', PathMatcher::normalise('foo'));
    }

    public function testNormaliseStripsTrailingSlashExceptRoot(): void
    {
        self::assertSame('/foo/bar', PathMatcher::normalise('/foo/bar/'));
        self::assertSame('/foo', PathMatcher::normalise('/foo'));
        self::assertSame('/', PathMatcher::normalise('/'));
        self::assertSame('/', PathMatcher::normalise(''));
    }

    public function testExactMatch(): void
    {
        self::assertNotNull(PathMatcher::match('/v1/search/web', '/v1/search/web'));
        self::assertNull(PathMatcher::match('/v1/search/web', '/v1/search/news'));
    }

    public function testParameterCapture(): void
    {
        $params = PathMatcher::match('/:version/search/:type', '/2/search/web');
        self::assertSame(['version' => '2', 'type' => 'web'], $params);
    }

    public function testParameterSkipsEmptySegment(): void
    {
        self::assertNull(PathMatcher::match('/:version/search/web', '//search/web'));
    }

    public function testRejectsInvalidParamName(): void
    {
        self::assertNull(PathMatcher::match('/:1bad/x', '/2/x'));
    }

    public function testDifferentSegmentCountWithoutWildcard(): void
    {
        self::assertNull(PathMatcher::match('/a/b', '/a/b/c'));
        self::assertNull(PathMatcher::match('/a/b/c', '/a/b'));
    }

    public function testWildcardMatchesZeroOrMoreTrailing(): void
    {
        self::assertNotNull(PathMatcher::match('/files/*', '/files'));
        $params = PathMatcher::match('/files/*', '/files/a/b/c');
        self::assertSame(['*' => 'a/b/c'], $params);
    }

    public function testWildcardRejectsWhenPrefixMismatches(): void
    {
        self::assertNull(PathMatcher::match('/files/*', '/folder/a'));
    }

    public function testWildcardWithStaticPrefixAndParam(): void
    {
        $params = PathMatcher::match('/users/:id/files/*', '/users/42/files/a/b');
        self::assertSame(['id' => '42', '*' => 'a/b'], $params);
    }

    public function testRootPatternMatchesRootOnly(): void
    {
        self::assertNotNull(PathMatcher::match('/', '/'));
        self::assertNull(PathMatcher::match('/', '/foo'));
    }

    public function testNormaliseDoesNotProduceDoubleSlash(): void
    {
        self::assertSame('/foo', PathMatcher::normalise('///foo'));
        self::assertSame('/foo', PathMatcher::normalise('/foo//'));
    }
}
