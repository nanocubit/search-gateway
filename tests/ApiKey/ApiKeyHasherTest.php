<?php

declare(strict_types=1);

namespace SearchGateway\Tests\ApiKey;

use PHPUnit\Framework\TestCase;
use SearchGateway\ApiKey\ApiKey;
use SearchGateway\ApiKey\ApiKeyHasher;

final class ApiKeyHasherTest extends TestCase
{
    public function testGeneratedRawKeyHasSgwPrefix(): void
    {
        $hasher = new ApiKeyHasher();
        $raw = $hasher->generateRawKey();

        self::assertStringStartsWith('sgw_', $raw);
        self::assertGreaterThan(20, strlen($raw));
    }

    public function testGenerateRawKeyProducesUniqueValues(): void
    {
        $hasher = new ApiKeyHasher();
        $a = $hasher->generateRawKey();
        $b = $hasher->generateRawKey();

        self::assertNotSame($a, $b);
    }

    public function testHashAndVerifyRoundTrip(): void
    {
        $hasher = new ApiKeyHasher();
        $raw = $hasher->generateRawKey();
        $hash = $hasher->hash($raw);

        self::assertNotSame($raw, $hash);
        self::assertTrue($hasher->verify($raw, $hash));
        self::assertFalse($hasher->verify('sgw_wrong', $hash));
    }

    public function testVerifyRejectsEmptyInputs(): void
    {
        $hasher = new ApiKeyHasher();
        $hash = $hasher->hash('sgw_x');

        self::assertFalse($hasher->verify('', $hash));
        self::assertFalse($hasher->verify('sgw_x', ''));
        self::assertFalse($hasher->verify('', ''));
    }

    public function testPublicPrefixReducesLongKeys(): void
    {
        $hasher = new ApiKeyHasher();
        $raw = 'sgw_' . str_repeat('a', 40);
        $prefix = $hasher->publicPrefix($raw);

        self::assertStringEndsWith('...', $prefix);
        self::assertSame(15, strlen($prefix));
    }

    public function testPublicPrefixReturnsShortKeysVerbatim(): void
    {
        $hasher = new ApiKeyHasher();
        self::assertSame('sgw_short', $hasher->publicPrefix('sgw_short'));
    }
}
