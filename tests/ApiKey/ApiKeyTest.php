<?php

declare(strict_types=1);

namespace SearchGateway\Tests\ApiKey;

use PHPUnit\Framework\TestCase;
use SearchGateway\ApiKey\ApiKey;

final class ApiKeyTest extends TestCase
{
    public function testStoresAndExposesAllFields(): void
    {
        $key = new ApiKey(
            id: 'k1',
            hash: 'h1',
            scopes: ['search:web', 'search:gen'],
            rateLimit: ['limit' => 100, 'window' => 60],
            owner: 'user-1',
            createdAt: 1000,
            expiresAt: 2000,
            revokedAt: null,
            prefix: 'sgw_abc...',
        );

        self::assertSame('k1', $key->id());
        self::assertSame('h1', $key->hash());
        self::assertSame(['search:web', 'search:gen'], $key->scopes());
        self::assertSame(['limit' => 100, 'window' => 60], $key->rateLimit());
        self::assertSame('user-1', $key->owner());
        self::assertSame(1000, $key->createdAt());
        self::assertSame(2000, $key->expiresAt());
        self::assertNull($key->revokedAt());
        self::assertSame('sgw_abc...', $key->prefix());
    }

    public function testIsRevokedReflectsRevokedAt(): void
    {
        $active = $this->makeKey(revokedAt: null);
        $revoked = $this->makeKey(revokedAt: 1500);

        self::assertFalse($active->isRevoked());
        self::assertTrue($revoked->isRevoked());
    }

    public function testIsExpiredRespectsExpirationBoundary(): void
    {
        $noExpiry = $this->makeKey(expiresAt: null);
        $expired = $this->makeKey(expiresAt: 1000);

        self::assertFalse($noExpiry->isExpired(99999));
        self::assertTrue($expired->isExpired(1000));
        self::assertFalse($expired->isExpired(999));
    }

    public function testIsActiveCombinesRevokedAndExpired(): void
    {
        $active = $this->makeKey();
        $revoked = $this->makeKey(revokedAt: 500);
        $expired = $this->makeKey(expiresAt: 100);

        self::assertTrue($active->isActive(50));
        self::assertFalse($revoked->isActive(50));
        self::assertFalse($expired->isActive(100));
    }

    public function testHasScopeMatchesExactAndWildcard(): void
    {
        $key = $this->makeKey(scopes: ['search:web']);

        self::assertTrue($key->hasScope('search:web'));
        self::assertFalse($key->hasScope('search:gen'));

        $adminKey = $this->makeKey(scopes: ['*']);
        self::assertTrue($adminKey->hasScope('search:web'));
        self::assertTrue($adminKey->hasScope('admin:routes'));
    }

    public function testToArrayAndFromArrayRoundTrip(): void
    {
        $key = $this->makeKey(scopes: ['search:web'], expiresAt: 9999);
        $arr = $key->toArray();
        $restored = ApiKey::fromArray($arr);

        self::assertSame($key->id(), $restored->id());
        self::assertSame($key->hash(), $restored->hash());
        self::assertSame($key->scopes(), $restored->scopes());
        self::assertSame($key->rateLimit(), $restored->rateLimit());
        self::assertSame($key->owner(), $restored->owner());
        self::assertSame($key->createdAt(), $restored->createdAt());
        self::assertSame($key->expiresAt(), $restored->expiresAt());
        self::assertNull($restored->revokedAt());
    }

    public function testFromArrayRejectsInvalidTypes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ApiKey::fromArray(['id' => 123, 'hash' => 'h', 'owner' => 'o', 'createdAt' => 1]);
    }

    public function testPrefixDefaultsToIdWhenNotProvided(): void
    {
        $key = new ApiKey(
            id: 'k1',
            hash: 'h',
            scopes: [],
            rateLimit: null,
            owner: 'o',
            createdAt: 0,
        );
        self::assertSame('k1', $key->prefix());
    }

    /**
     * @param list<string> $scopes
     */
    private function makeKey(array $scopes = [], ?int $expiresAt = null, ?int $revokedAt = null): ApiKey
    {
        return new ApiKey(
            id: 'k-' . bin2hex(random_bytes(2)),
            hash: 'h-' . bin2hex(random_bytes(2)),
            scopes: $scopes,
            rateLimit: null,
            owner: 'owner',
            createdAt: 0,
            expiresAt: $expiresAt,
            revokedAt: $revokedAt,
        );
    }
}
