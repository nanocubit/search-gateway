<?php

declare(strict_types=1);

namespace SearchGateway\ApiKey;

final class ApiKeyHasher
{
    public const PREFIX = 'sgw_';

    /**
     * @param int<1, 1024> $randomBytes
     */
    public function generateRawKey(int $randomBytes = 32): string
    {
        try {
            $random = random_bytes($randomBytes);
        } catch (\Throwable) {
            throw new \RuntimeException('Unable to generate secure random bytes for API key');
        }
        return self::PREFIX . rtrim(strtr(base64_encode($random), '+/', '-_'), '=');
    }

    public function hash(string $rawKey): string
    {
        return password_hash($rawKey, PASSWORD_BCRYPT);
    }

    public function verify(string $rawKey, string $hash): bool
    {
        if ($hash === '' || $rawKey === '') {
            return false;
        }
        return password_verify($rawKey, $hash);
    }

    public function publicPrefix(string $rawKey): string
    {
        if (strlen($rawKey) <= 12) {
            return $rawKey;
        }
        return substr($rawKey, 0, 12) . '...';
    }
}
