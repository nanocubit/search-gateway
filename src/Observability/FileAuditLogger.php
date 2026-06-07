<?php

declare(strict_types=1);

namespace SearchGateway\Observability;

use RuntimeException;

final class FileAuditLogger implements AuditLoggerInterface
{
    public function __construct(
        private readonly string $filePath,
        private readonly int $maxEvents = 10000,
    ) {
    }

    public function log(string $action, string $actor, array $context = []): void
    {
        $line = (string) json_encode([
            'ts' => microtime(true),
            'action' => $action,
            'actor' => $actor,
            'context' => $context,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $fp = @fopen($this->filePath, 'ab');
        if ($fp === false) {
            throw new RuntimeException('Cannot open audit log: ' . $this->filePath);
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            throw new RuntimeException('Cannot lock audit log: ' . $this->filePath);
        }
        try {
            if (file_exists($this->filePath)) {
                $size = filesize($this->filePath) ?: 0;
                if ($size > 0 && $size >= $this->maxEvents * 256) {
                    ftruncate($fp, 0);
                    rewind($fp);
                }
            }
            fwrite($fp, $line . "\n");
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    public function events(int $limit = 100): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }
        $lines = @file($this->filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }
        $events = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }
        if ($limit <= 0) {
            return $events;
        }
        return array_slice($events, -$limit);
    }

    public function count(): int
    {
        if (!file_exists($this->filePath)) {
            return 0;
        }
        $count = 0;
        $fp = @fopen($this->filePath, 'rb');
        if ($fp === false) {
            return 0;
        }
        try {
            while (!feof($fp)) {
                $line = fgets($fp);
                if ($line !== false && trim($line) !== '') {
                    $count++;
                }
            }
        } finally {
            fclose($fp);
        }
        return $count;
    }
}
