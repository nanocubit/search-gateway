<?php

declare(strict_types=1);

namespace SearchGateway\Admin;

use SearchGateway\Contract\SearchGatewayException;

final class AdminAuth
{
    public const ROLE_SUPER = 'admin:*';
    public const ROLE_ROUTES = 'admin:routes';
    public const ROLE_KEYS = 'admin:keys';
    public const ROLE_READ = 'admin:read';

    public function __construct(
        private readonly ?string $token = null,
        private readonly ?string $envVar = 'SGW_ADMIN_TOKEN',
    ) {
    }

    public function token(): ?string
    {
        if ($this->token !== null && $this->token !== '') {
            return $this->token;
        }
        if ($this->envVar === null) {
            return null;
        }
        $env = getenv($this->envVar);
        return is_string($env) && $env !== '' ? $env : null;
    }

    public function isEnabled(): bool
    {
        return $this->token() !== null;
    }

    /**
     * @return list<string>
     */
    public function rolesForToken(string $rawToken): array
    {
        $expected = $this->token();
        if ($expected === null) {
            return [];
        }
        if (!hash_equals($expected, $rawToken)) {
            return [];
        }
        return [self::ROLE_SUPER, self::ROLE_ROUTES, self::ROLE_KEYS, self::ROLE_READ];
    }

    /**
     * @param list<string> $required
     * @throws SearchGatewayException
     */
    public function authenticate(string $rawToken, array $required = [self::ROLE_SUPER]): void
    {
        if (!$this->isEnabled()) {
            throw new SearchGatewayException('Admin API is disabled (no SGW_ADMIN_TOKEN)', 503);
        }
        $granted = $this->rolesForToken($rawToken);
        if ($granted === []) {
            throw new SearchGatewayException('Invalid admin token', 401);
        }
        foreach ($required as $role) {
            if (in_array($role, $granted, true)) {
                return;
            }
        }
        throw new SearchGatewayException('Insufficient admin scope', 403);
    }

    public function extractBearer(?string $header): ?string
    {
        if ($header === null || $header === '') {
            return null;
        }
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return null;
        }
        return trim($m[1]);
    }
}
