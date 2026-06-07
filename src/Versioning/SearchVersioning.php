<?php

declare(strict_types=1);

namespace SearchGateway\Versioning;

/**
 * Versioning для search prompts и configurations.
 * A/B testing, rollback, audit trail.
 */
final class SearchVersioning
{
    /** @var array<string, list<array{config: array<string, mixed>, timestamp: int, hash: string}>> */
    private array $versions = [];

    /**
     * @param array<string, mixed> $config
     */
    public function snapshot(string $name, array $config): string
    {
        $hash = md5(json_encode($config, JSON_THROW_ON_ERROR));
        $this->versions[$name][] = [
            'config' => $config,
            'timestamp' => time(),
            'hash' => $hash,
        ];
        return $hash;
    }

    /**
     * @return array{config: array<string, mixed>, timestamp: int, hash: string}|null
     */
    public function get(string $name, ?string $hash = null): ?array
    {
        $versions = $this->versions[$name] ?? [];
        if ($versions === []) {
            return null;
        }
        if ($hash === null) {
            return $versions[count($versions) - 1];
        }
        foreach ($versions as $v) {
            if (is_array($v) && $v['hash'] === $hash) {
                return $v;
            }
        }
        return null;
    }

    /**
     * @return array{added: array<string, mixed>, removed: array<string, mixed>, changed: array<string, array{from: mixed, to: mixed}>}
     */
    public function diff(string $name, string $hashA, string $hashB): array
    {
        $aVer = $this->get($name, $hashA);
        $bVer = $this->get($name, $hashB);
        $a = is_array($aVer) ? $aVer['config'] : [];
        $b = is_array($bVer) ? $bVer['config'] : [];

        $added = [];
        $removed = [];
        $changed = [];

        foreach ($b as $key => $val) {
            $keyStr = (string) $key;
            if (!array_key_exists($keyStr, $a)) {
                $added[$keyStr] = $val;
            } elseif ($a[$keyStr] !== $val) {
                $changed[$keyStr] = ['from' => $a[$keyStr], 'to' => $val];
            }
        }
        foreach ($a as $key => $val) {
            $keyStr = (string) $key;
            if (array_key_exists($keyStr, $b)) {
                continue;
            }
            $removed[$keyStr] = $val;
        }

        return ['added' => $added, 'removed' => $removed, 'changed' => $changed];
    }

    /**
     * @return list<array{hash: string, timestamp: int}>
     */
    public function history(string $name): array
    {
        $versions = $this->versions[$name] ?? [];
        $out = [];
        foreach ($versions as $v) {
            if (is_array($v)) {
                $out[] = [
                    'hash' => $v['hash'],
                    'timestamp' => $v['timestamp'],
                ];
            }
        }
        return $out;
    }
}
