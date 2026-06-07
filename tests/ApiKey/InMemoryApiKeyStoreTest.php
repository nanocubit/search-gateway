<?php

declare(strict_types=1);

namespace SearchGateway\Tests\ApiKey;

use PHPUnit\Framework\TestCase;
use SearchGateway\ApiKey\ApiKey;
use SearchGateway\ApiKey\InMemoryApiKeyStore;

final class InMemoryApiKeyStoreTest extends TestCase
{
    public function testSaveAndFindById(): void
    {
        $store = new InMemoryApiKeyStore();
        $key = $this->makeKey('a');
        $store->save($key);

        self::assertSame($key, $store->findById('a'));
        self::assertNull($store->findById('missing'));
    }

    public function testFindByHashLooksUpByHash(): void
    {
        $store = new InMemoryApiKeyStore();
        $key = $this->makeKey('a', 'hash-1');
        $store->save($key);

        self::assertSame($key, $store->findByHash('hash-1'));
        self::assertNull($store->findByHash('hash-2'));
    }

    public function testAllReturnsAllKeys(): void
    {
        $store = new InMemoryApiKeyStore();
        $store->save($this->makeKey('a'));
        $store->save($this->makeKey('b'));
        $store->save($this->makeKey('c'));

        self::assertCount(3, $store->all());
    }

    public function testDeleteRemovesKeyAndHashIndex(): void
    {
        $store = new InMemoryApiKeyStore();
        $key = $this->makeKey('a', 'hash-1');
        $store->save($key);

        self::assertTrue($store->delete('a'));
        self::assertFalse($store->delete('a'));
        self::assertNull($store->findById('a'));
        self::assertNull($store->findByHash('hash-1'));
    }

    public function testCountTracksSize(): void
    {
        $store = new InMemoryApiKeyStore();
        self::assertSame(0, $store->count());
        $store->save($this->makeKey('a'));
        $store->save($this->makeKey('b'));
        self::assertSame(2, $store->count());
        $store->delete('a');
        self::assertSame(1, $store->count());
    }

    public function testSaveOverwritesKeyWithSameId(): void
    {
        $store = new InMemoryApiKeyStore();
        $store->save($this->makeKey('a', 'h1'));
        $store->save($this->makeKey('a', 'h2'));

        self::assertSame(1, $store->count());
        $reloaded = $store->findById('a');
        self::assertNotNull($reloaded);
        self::assertSame('h2', $reloaded->hash());
    }

    private function makeKey(string $id, string $hash = 'h'): ApiKey
    {
        return new ApiKey(
            id: $id,
            hash: $hash,
            scopes: [],
            rateLimit: null,
            owner: 'o',
            createdAt: 0,
        );
    }
}
