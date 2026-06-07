<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SearchGateway\Admin\KeyAdminService;
use SearchGateway\ApiKey\ApiKeyManager;
use SearchGateway\ApiKey\InMemoryApiKeyStore;

final class KeyAdminServiceTest extends TestCase
{
    private KeyAdminService $service;
    private ApiKeyManager $manager;

    protected function setUp(): void
    {
        $this->manager = new ApiKeyManager(new InMemoryApiKeyStore(), clock: static fn (): int => 1000);
        $this->service = new KeyAdminService($this->manager);
    }

    public function testListReturnsAllKeys(): void
    {
        $this->manager->create('a');
        $this->manager->create('b');

        self::assertCount(2, $this->service->list());
    }

    public function testCreateReturnsRawKeyOnce(): void
    {
        $row = $this->service->create([
            'owner' => 'user-1',
            'scopes' => ['search:web'],
            'rateLimit' => ['limit' => 50, 'window' => 60],
        ]);

        self::assertArrayHasKey('rawKey', $row);
        $raw = $row['rawKey'];
        self::assertIsString($raw);
        self::assertStringStartsWith('sgw_', $raw);
        self::assertSame('user-1', $row['owner']);
        self::assertSame(['search:web'], $row['scopes']);
        self::assertSame(['limit' => 50, 'window' => 60], $row['rateLimit']);
        self::assertTrue($row['active']);
    }

    public function testCreateThrowsOnMissingOwner(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create(['scopes' => []]);
    }

    public function testCreateThrowsOnNonArrayScopes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create(['owner' => 'o', 'scopes' => 'invalid']);
    }

    public function testRevokeReturnsTrueForExistingKey(): void
    {
        [, $key] = $this->manager->create('o');

        self::assertTrue($this->service->revoke($key->id()));
        self::assertFalse($this->service->revoke($key->id()));
    }

    public function testFindReturnsRowOrNull(): void
    {
        [, $key] = $this->manager->create('o');
        self::assertNotNull($this->service->find($key->id()));
        self::assertNull($this->service->find('missing'));
    }

    public function testCountReflectsActiveKeys(): void
    {
        self::assertSame(0, $this->service->count());
        $this->manager->create('a');
        $this->manager->create('b');
        self::assertSame(2, $this->service->count());
    }
}
