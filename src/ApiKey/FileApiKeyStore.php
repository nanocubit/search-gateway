<?php

declare(strict_types=1);

namespace SearchGateway\ApiKey;

final class FileApiKeyStore implements ApiKeyStoreInterface
{
    private string $path;
    private string $lockPath;

    public function __construct(string $path)
    {
        $this->path = $path;
        $this->lockPath = $path . '.lock';
    }

    public function save(ApiKey $key): void
    {
        $this->withLock(function (array $data) use ($key): array {
            $data[$key->id()] = $key->toArray();
            return $data;
        });
    }

    public function findById(string $id): ?ApiKey
    {
        $data = $this->readAll();
        $entry = $data[$id] ?? null;
        if ($entry === null) {
            return null;
        }
        return $this->decode($entry);
    }

    public function findByHash(string $hash): ?ApiKey
    {
        $data = $this->readAll();
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $h = $entry['hash'] ?? null;
            if (is_string($h) && hash_equals($h, $hash)) {
                return $this->decode($entry);
            }
        }
        return null;
    }

    /**
     * @return list<ApiKey>
     */
    public function all(): array
    {
        $data = $this->readAll();
        $keys = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $keys[] = $this->decode($entry);
        }
        return $keys;
    }

    public function delete(string $id): bool
    {
        $removed = false;
        $this->withLock(function (array $data) use ($id, &$removed): array {
            if (isset($data[$id])) {
                unset($data[$id]);
                $removed = true;
            }
            return $data;
        });
        return $removed;
    }

    public function count(): int
    {
        return count($this->readAll());
    }

    /**
     * @param callable(array<string, array<string, mixed>>): array<string, array<string, mixed>> $mutator
     */
    private function withLock(callable $mutator): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        $handle = fopen($this->lockPath, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open lock file: ' . $this->lockPath);
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Cannot acquire lock: ' . $this->lockPath);
            }
            $current = $this->readAll();
            $next = $mutator($current);
            $this->writeAll($next);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readAll(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $raw = (string) file_get_contents($this->path);
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, array<string, mixed>> $data
     */
    private function writeAll(array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $tmp = $this->path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write to temp file: ' . $tmp);
        }
        if (!rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot rename temp file to: ' . $this->path);
        }
        @chmod($this->path, 0o600);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function decode(array $entry): ApiKey
    {
        return ApiKey::fromArray($entry);
    }
}
