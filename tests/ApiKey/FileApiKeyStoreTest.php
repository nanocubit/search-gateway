<?php

declare(strict_types=1);

namespace SearchGateway\Tests\ApiKey;

use PHPUnit\Framework\TestCase;
use SearchGateway\ApiKey\ApiKey;
use SearchGateway\ApiKey\ApiKeyManager;
use SearchGateway\ApiKey\FileApiKeyStore;

final class FileApiKeyStoreTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sgw-test-' . bin2hex(random_bytes(4));
        @mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $this->rrmdir($this->dir);
        }
    }

    public function testEmptyStoreReturnsZeroAndNull(): void
    {
        $store = new FileApiKeyStore($this->dir . '/keys.json');
        self::assertSame(0, $store->count());
        self::assertSame([], $store->all());
        self::assertNull($store->findById('any'));
    }

    public function testSaveAndFindById(): void
    {
        $store = new FileApiKeyStore($this->dir . '/keys.json');
        $key = $this->makeKey('a', 'hash-a');
        $store->save($key);

        self::assertSame(1, $store->count());
        $loaded = $store->findById('a');
        self::assertNotNull($loaded);
        self::assertSame('a', $loaded->id());
        self::assertSame('hash-a', $loaded->hash());
    }

    public function testFindByHashUsesConstantTimeComparison(): void
    {
        $store = new FileApiKeyStore($this->dir . '/keys.json');
        $store->save($this->makeKey('a', 'hash-a'));
        $store->save($this->makeKey('b', 'hash-b'));

        self::assertNotNull($store->findByHash('hash-a'));
        self::assertNotNull($store->findByHash('hash-b'));
        self::assertNull($store->findByHash('hash-c'));
    }

    public function testDeleteRemovesKey(): void
    {
        $store = new FileApiKeyStore($this->dir . '/keys.json');
        $store->save($this->makeKey('a', 'hash-a'));

        self::assertTrue($store->delete('a'));
        self::assertFalse($store->delete('a'));
        self::assertSame(0, $store->count());
    }

    public function testAllReturnsAllKeys(): void
    {
        $store = new FileApiKeyStore($this->dir . '/keys.json');
        $store->save($this->makeKey('a'));
        $store->save($this->makeKey('b'));

        self::assertCount(2, $store->all());
    }

    public function testManagerEndToEndPersistsAcrossInstances(): void
    {
        $path = $this->dir . '/keys.json';
        $a = new ApiKeyManager(new FileApiKeyStore($path), clock: static fn (): int => 1000);
        [$raw, $created] = $a->create('owner-1', ['search:web']);

        $b = new ApiKeyManager(new FileApiKeyStore($path), clock: static fn (): int => 1500);
        $verified = $b->verify($raw);

        self::assertNotNull($verified);
        self::assertSame($created->id(), $verified->id());
        self::assertTrue($verified->isActive(1500));
    }

    public function testCorruptedJsonFileIsTreatedAsEmpty(): void
    {
        $path = $this->dir . '/keys.json';
        file_put_contents($path, '{not valid json');
        $store = new FileApiKeyStore($path);

        self::assertSame(0, $store->count());
        $store->save($this->makeKey('a'));
        self::assertSame(1, $store->count());
    }

    public function testFileIsWrittenWithSecurePermissions(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX permission checks not relevant on Windows');
        }
        if (!function_exists('posix_geteuid')) {
            self::markTestSkipped('posix extension not available');
        }
        $path = $this->dir . '/keys.json';
        $store = new FileApiKeyStore($path);
        $store->save($this->makeKey('a'));

        $perms = fileperms($path) & 0o777;
        self::assertSame(0o600, $perms);
    }

    public function testConcurrentSavesAreSerialized(): void
    {
        $path = $this->dir . '/keys.json';
        $store = new FileApiKeyStore($path);
        for ($i = 0; $i < 10; $i++) {
            $store->save($this->makeKey('k-' . $i, 'h-' . $i));
        }

        $loaded = new FileApiKeyStore($path);
        self::assertSame(10, $loaded->count());
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

    private function rrmdir(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rrmdir($path . '/' . $entry);
        }
        rmdir($path);
    }
}
