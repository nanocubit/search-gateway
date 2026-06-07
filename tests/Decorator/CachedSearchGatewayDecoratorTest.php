<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Decorator;

use PHPUnit\Framework\TestCase;
use SearchGateway\Contract\GenerativeSearchResultDTO;
use SearchGateway\Contract\SearchGatewayInterface;
use SearchGateway\Decorator\CachedSearchGatewayDecorator;
use SearchGateway\Infrastructure\CacheInterface;

final class CachedSearchGatewayDecoratorTest extends TestCase
{
    public function testCachesWebSearch(): void
    {
        $inner = $this->createMock(SearchGatewayInterface::class);
        $inner->expects($this->once())
            ->method('searchWeb')
            ->willReturn([['title' => 'Cached']]);

        $cache = new class implements CacheInterface {
            /** @var array<string, mixed> */
            private array $store = [];
            public function get(string $key): mixed
            {
                return $this->store[$key] ?? null;
            }
            public function set(string $key, mixed $value, int $ttlSeconds): bool
            {
                $this->store[$key] = $value;
                return true;
            }
        };

        $decorator = new CachedSearchGatewayDecorator($inner, $cache, 60);

        $r1 = $decorator->searchWeb('q');
        $r2 = $decorator->searchWeb('q');

        $this->assertSame($r1, $r2);
    }

    public function testCachesGenResult(): void
    {
        $dto = new GenerativeSearchResultDTO(answer: 'Yes', sources: [], meta: []);

        $inner = $this->createMock(SearchGatewayInterface::class);
        $inner->expects($this->once())
            ->method('searchGen')
            ->willReturn($dto);

        $cache = new class implements CacheInterface {
            /** @var array<string, mixed> */
            private array $store = [];
            public function get(string $key): mixed
            {
                return $this->store[$key] ?? null;
            }
            public function set(string $key, mixed $value, int $ttlSeconds): bool
            {
                $this->store[$key] = $value;
                return true;
            }
        };

        $decorator = new CachedSearchGatewayDecorator($inner, $cache, 60);

        $r1 = $decorator->searchGen('q');
        $r2 = $decorator->searchGen('q');

        $this->assertSame($r1->answer, $r2->answer);
    }
}
