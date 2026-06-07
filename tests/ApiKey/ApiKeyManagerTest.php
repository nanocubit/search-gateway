<?php

declare(strict_types=1);

namespace SearchGateway\Tests\ApiKey;

use PHPUnit\Framework\TestCase;
use SearchGateway\ApiKey\ApiKey;
use SearchGateway\ApiKey\ApiKeyManager;
use SearchGateway\ApiKey\InMemoryApiKeyStore;
use SearchGateway\Contract\SearchGatewayException;

final class ApiKeyManagerTest extends TestCase
{
    public function testCreateReturnsRawKeyAndPersistsKey(): void
    {
        $manager = new ApiKeyManager(new InMemoryApiKeyStore(), clock: static fn (): int => 1000);

        [$raw, $key] = $manager->create('owner-1', ['search:web']);

        self::assertStringStartsWith('sgw_', $raw);
        self::assertSame('owner-1', $key->owner());
        self::assertSame(['search:web'], $key->scopes());
        self::assertSame(1000, $key->createdAt());
        self::assertStringStartsWith('sgw_', $key->prefix());
    }

    public function testVerifySucceedsForActiveKey(): void
    {
        $manager = new ApiKeyManager(new InMemoryApiKeyStore(), clock: static fn (): int => 1000);
        [$raw] = $manager->create('o', ['search:web']);

        $key = $manager->verify($raw);

        self::assertNotNull($key);
        self::assertSame('o', $key->owner());
    }

    public function testVerifyFailsForUnknownKey(): void
    {
        $manager = new ApiKeyManager(new InMemoryApiKeyStore());
        self::assertNull($manager->verify('sgw_unknown'));
    }

    public function testVerifyFailsForEmptyOrWrongPrefix(): void
    {
        $manager = new ApiKeyManager(new InMemoryApiKeyStore());
        self::assertNull($manager->verify(''));
        self::assertNull($manager->verify('xxx_abc'));
    }

    public function testVerifyFailsForRevokedKey(): void
    {
        $manager = new ApiKeyManager(new InMemoryApiKeyStore(), clock: static fn (): int => 1000);
        [$raw, $key] = $manager->create('o', []);

        $manager->revoke($key->id());

        self::assertNull($manager->verify($raw));
    }

    public function testVerifyFailsForExpiredKey(): void
    {
        $clock = 1000;
        $manager = new ApiKeyManager(new InMemoryApiKeyStore(), clock: static function () use (&$clock): int {
            return $clock;
        });
        [$raw] = $manager->create('o', [], null, 1000);

        $clock = 1500;
        self::assertNull($manager->verify($raw));
    }

    public function testVerifyWithScopesEnforcesAll(): void
    {
        $manager = new ApiKeyManager(new InMemoryApiKeyStore(), clock: static fn (): int => 1000);
        [$raw] = $manager->create('o', ['search:web', 'search:gen']);

        self::assertNotNull($manager->verifyWithScopes($raw, ['search:web']));
        self::assertNotNull($manager->verifyWithScopes($raw, ['search:web', 'search:gen']));
        self::assertNull($manager->verifyWithScopes($raw, ['admin:routes']));
        self::assertNull($manager->verifyWithScopes($raw, ['search:web', 'missing:scope']));
    }

    public function testRevokeMarksKeyRevokedAndReturnsTrue(): void
    {
        $manager = new ApiKeyManager(new InMemoryApiKeyStore(), clock: static fn (): int => 1000);
        [, $key] = $manager->create('o', []);

        self::assertTrue($manager->revoke($key->id()));
        $reloaded = $manager->find($key->id());
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isRevoked());
        self::assertSame(1000, $reloaded->revokedAt());
    }

    public function testRevokeOnMissingOrAlreadyRevokedReturnsFalse(): void
    {
        $manager = new ApiKeyManager(new InMemoryApiKeyStore());
        self::assertFalse($manager->revoke('missing'));

        [, $key] = $manager->create('o', []);
        $manager->revoke($key->id());
        self::assertFalse($manager->revoke($key->id()));
    }

    public function testAuthenticateThrows401OnUnknownAnd403OnMissingScope(): void
    {
        $manager = new ApiKeyManager(new InMemoryApiKeyStore(), clock: static fn (): int => 1000);
        [$raw] = $manager->create('o', ['search:web']);

        try {
            $manager->authenticate('sgw_unknown');
            self::fail('Expected 401');
        } catch (SearchGatewayException $e) {
            self::assertSame(401, $e->getCode());
        }

        try {
            $manager->authenticate($raw, ['admin:routes']);
            self::fail('Expected 403');
        } catch (SearchGatewayException $e) {
            self::assertSame(403, $e->getCode());
        }
    }

    public function testAuthenticateReturnsKeyOnSuccess(): void
    {
        $manager = new ApiKeyManager(new InMemoryApiKeyStore(), clock: static fn (): int => 1000);
        [$raw] = $manager->create('o', ['search:web']);

        $key = $manager->authenticate($raw);
        self::assertSame('o', $key->owner());

        $key = $manager->authenticate($raw, ['search:web']);
        self::assertSame('o', $key->owner());
    }

    public function testListAndDelete(): void
    {
        $manager = new ApiKeyManager(new InMemoryApiKeyStore());
        $manager->create('a');
        $manager->create('b');
        self::assertCount(2, $manager->list());

        $firstId = $manager->list()[0]->id();
        self::assertTrue($manager->delete($firstId));
        self::assertCount(1, $manager->list());
    }

    public function testCreateFiltersNonStringScopes(): void
    {
        $manager = new ApiKeyManager(new InMemoryApiKeyStore());
        $scopes = ['valid', 123, null, 'also-valid'];
        /** @var list<string> $typedScopes */
        $typedScopes = array_values(array_filter($scopes, 'is_string'));
        [, $key] = $manager->create('o', $typedScopes);

        self::assertSame(['valid', 'also-valid'], $key->scopes());
    }
}
