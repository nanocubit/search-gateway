<?php

declare(strict_types=1);

namespace SearchGateway\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SearchGateway\Admin\AdminAuth;
use SearchGateway\Contract\SearchGatewayException;

final class AdminAuthTest extends TestCase
{
    public function testIsDisabledWhenNoTokenConfigured(): void
    {
        $auth = new AdminAuth(token: '', envVar: null);
        self::assertFalse($auth->isEnabled());
        self::assertNull($auth->token());
    }

    public function testIsEnabledWithExplicitToken(): void
    {
        $auth = new AdminAuth(token: 'secret-123');
        self::assertTrue($auth->isEnabled());
        self::assertSame('secret-123', $auth->token());
    }

    public function testIsEnabledViaEnvVar(): void
    {
        putenv('SGW_ADMIN_TOKEN_TEST=env-secret');
        try {
            $auth = new AdminAuth(token: '', envVar: 'SGW_ADMIN_TOKEN_TEST');
            self::assertTrue($auth->isEnabled());
            self::assertSame('env-secret', $auth->token());
        } finally {
            putenv('SGW_ADMIN_TOKEN_TEST');
        }
    }

    public function testRolesForTokenReturnsAllRolesForMatchingToken(): void
    {
        $auth = new AdminAuth(token: 'secret');
        $roles = $auth->rolesForToken('secret');

        self::assertContains(AdminAuth::ROLE_SUPER, $roles);
        self::assertContains(AdminAuth::ROLE_ROUTES, $roles);
        self::assertContains(AdminAuth::ROLE_KEYS, $roles);
        self::assertContains(AdminAuth::ROLE_READ, $roles);
    }

    public function testRolesForTokenReturnsEmptyOnMismatch(): void
    {
        $auth = new AdminAuth(token: 'secret');
        self::assertSame([], $auth->rolesForToken('wrong'));
    }

    public function testRolesForTokenReturnsEmptyWhenDisabled(): void
    {
        $auth = new AdminAuth(token: null, envVar: null);
        self::assertSame([], $auth->rolesForToken('any'));
    }

    public function testAuthenticateThrows503WhenDisabled(): void
    {
        $auth = new AdminAuth(token: null, envVar: null);
        $this->expectException(SearchGatewayException::class);
        $this->expectExceptionCode(503);
        $auth->authenticate('any');
    }

    public function testAuthenticateThrows401OnWrongToken(): void
    {
        $auth = new AdminAuth(token: 'secret');
        $this->expectException(SearchGatewayException::class);
        $this->expectExceptionCode(401);
        $auth->authenticate('wrong');
    }

    public function testAuthenticateThrows403OnMissingScope(): void
    {
        $auth = new AdminAuth(token: 'secret');
        $this->expectException(SearchGatewayException::class);
        $this->expectExceptionCode(403);
        $auth->authenticate('secret', ['admin:undefined']);
    }

    public function testAuthenticateSucceedsWithSuperRole(): void
    {
        $auth = new AdminAuth(token: 'secret');
        $auth->authenticate('secret');
        $auth->authenticate('secret', [AdminAuth::ROLE_KEYS, AdminAuth::ROLE_ROUTES]);
        self::assertTrue(true);
    }

    public function testExtractBearerParsesHeader(): void
    {
        $auth = new AdminAuth(token: 'secret');
        self::assertSame('abc', $auth->extractBearer('Bearer abc'));
        self::assertSame('abc', $auth->extractBearer('bearer abc'));
        self::assertNull($auth->extractBearer('Basic xyz'));
        self::assertNull($auth->extractBearer(''));
        self::assertNull($auth->extractBearer(null));
    }
}
