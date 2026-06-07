<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Observability;

use PHPUnit\Framework\TestCase;
use SearchGateway\Observability\FileAuditLogger;
use SearchGateway\Observability\InMemoryAuditLogger;

final class AuditLoggerTest extends TestCase
{
    public function testInMemoryLogsAndRetrieves(): void
    {
        $logger = new InMemoryAuditLogger();
        $logger->log('route.register', 'admin', ['name' => 'v1.web']);
        $logger->log('key.create', 'admin', ['id' => 'k_1']);

        self::assertSame(2, $logger->count());
        $events = $logger->events();
        self::assertIsArray($events);
        $first = $events[0];
        self::assertIsArray($first);
        self::assertSame('route.register', $first['action'] ?? null);
        self::assertSame('admin', $first['actor'] ?? null);
        $ctx = $first['context'] ?? [];
        self::assertIsArray($ctx);
        self::assertSame('v1.web', $ctx['name'] ?? null);
        $second = $events[1];
        self::assertIsArray($second);
        self::assertSame('key.create', $second['action'] ?? null);
    }

    public function testInMemoryLimitsReturnedEvents(): void
    {
        $logger = new InMemoryAuditLogger();
        for ($i = 0; $i < 10; $i++) {
            $logger->log('tick', "u{$i}");
        }

        $tail = $logger->events(3);
        self::assertCount(3, $tail);
        self::assertIsArray($tail[0]);
        self::assertSame('u7', $tail[0]['actor'] ?? null);
        self::assertIsArray($tail[1]);
        self::assertSame('u8', $tail[1]['actor'] ?? null);
        self::assertIsArray($tail[2]);
        self::assertSame('u9', $tail[2]['actor'] ?? null);
    }

    public function testInMemoryZeroLimitReturnsAll(): void
    {
        $logger = new InMemoryAuditLogger();
        for ($i = 0; $i < 5; $i++) {
            $logger->log('e', "u{$i}");
        }
        self::assertCount(5, $logger->events(0));
    }

    public function testInMemoryEventHasTimestamp(): void
    {
        $logger = new InMemoryAuditLogger();
        $logger->log('action', 'actor');
        $event = $logger->events()[0];
        self::assertIsArray($event);
        $ts = $event['ts'] ?? 0.0;

        self::assertIsFloat($ts);
        self::assertGreaterThan(0.0, $ts);
    }

    public function testFileAuditLoggerPersistsAcrossInstances(): void
    {
        $file = $this->tmpFile('audit');
        $a = new FileAuditLogger($file);
        $a->log('login', 'alice', ['ip' => '1.2.3.4']);
        $a->log('logout', 'alice');

        $b = new FileAuditLogger($file);
        self::assertSame(2, $b->count());
        $events = $b->events();
        self::assertIsArray($events);
        $first = $events[0];
        self::assertIsArray($first);
        self::assertSame('login', $first['action'] ?? null);
        $ctx = $first['context'] ?? [];
        self::assertIsArray($ctx);
        self::assertSame('1.2.3.4', $ctx['ip'] ?? null);
        $second = $events[1];
        self::assertIsArray($second);
        self::assertSame('logout', $second['action'] ?? null);

        unlink($file);
    }

    public function testFileAuditLoggerReturnsEmptyForMissingFile(): void
    {
        $file = $this->tmpFile('missing');
        $logger = new FileAuditLogger($file);
        self::assertSame(0, $logger->count());
        self::assertSame([], $logger->events());
    }

    public function testFileAuditLoggerFileLimitTruncates(): void
    {
        $file = $this->tmpFile('truncate');
        $logger = new FileAuditLogger($file, maxEvents: 2);
        $logger->log('a', 'u');
        $logger->log('b', 'u');
        $logger->log('c', 'u');

        $events = (new FileAuditLogger($file))->events();
        self::assertGreaterThanOrEqual(1, count($events));

        unlink($file);
    }

    private function tmpFile(string $tag): string
    {
        $dir = sys_get_temp_dir();
        $path = $dir . '/sgw-audit-' . $tag . '-' . bin2hex(random_bytes(4)) . '.log';
        if (file_exists($path)) {
            unlink($path);
        }
        return $path;
    }
}
