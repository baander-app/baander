<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application;

use App\Auth\Application\ScopeAllowlist;
use PHPUnit\Framework\TestCase;

final class ScopeAllowlistTest extends TestCase
{
    private ScopeAllowlist $allowlist;

    protected function setUp(): void
    {
        $this->allowlist = new ScopeAllowlist(
            userGrants: ['profile', 'email', 'library', 'playlist'],
            clientCredentials: ['admin'],
        );
    }

    public function testFilterKeepsAllowedUserScopes(): void
    {
        $result = $this->allowlist->filter(['profile', 'email', 'library'], 'authorization_code');

        $this->assertSame(['profile', 'email', 'library'], $result);
    }

    public function testFilterDropsDisallowedScopesFromUserGrant(): void
    {
        $result = $this->allowlist->filter(['profile', 'admin', 'nonexistent'], 'authorization_code');

        $this->assertSame(['profile'], $result);
    }

    public function testFilterKeepsAdminForClientCredentials(): void
    {
        $result = $this->allowlist->filter(['admin'], 'client_credentials');

        $this->assertSame(['admin'], $result);
    }

    public function testFilterDropsUserScopesFromClientCredentials(): void
    {
        $result = $this->allowlist->filter(['admin', 'profile', 'email'], 'client_credentials');

        $this->assertSame(['admin'], $result);
    }

    public function testFilterReturnsEmptyWhenNoScopesMatch(): void
    {
        $result = $this->allowlist->filter(['admin', 'nonexistent'], 'authorization_code');

        $this->assertSame([], $result);
    }

    public function testFilterPreservesOrder(): void
    {
        $result = $this->allowlist->filter(['playlist', 'email', 'profile'], 'authorization_code');

        $this->assertSame(['playlist', 'email', 'profile'], $result);
    }

    public function testFilterHandlesEmptyArray(): void
    {
        $result = $this->allowlist->filter([], 'authorization_code');

        $this->assertSame([], $result);
    }

    public function testGetAllowlistForGrantTypeReturnsUserGrantsByDefault(): void
    {
        $result = $this->allowlist->getAllowlistForGrantType('authorization_code');

        $this->assertSame(['profile', 'email', 'library', 'playlist'], $result);
    }

    public function testGetAllowlistForGrantTypeReturnsUserGrantsForRefreshToken(): void
    {
        $result = $this->allowlist->getAllowlistForGrantType('refresh_token');

        $this->assertSame(['profile', 'email', 'library', 'playlist'], $result);
    }

    public function testGetAllowlistForGrantTypeReturnsUserGrantsForDeviceCode(): void
    {
        $result = $this->allowlist->getAllowlistForGrantType('urn:ietf:params:oauth:grant-type:device_code');

        $this->assertSame(['profile', 'email', 'library', 'playlist'], $result);
    }

    public function testGetAllowlistForGrantTypeReturnsClientCredentialsForClientCredentials(): void
    {
        $result = $this->allowlist->getAllowlistForGrantType('client_credentials');

        $this->assertSame(['admin'], $result);
    }

    public function testGettersReturnConfiguredValues(): void
    {
        $this->assertSame(['profile', 'email', 'library', 'playlist'], $this->allowlist->getUserGrants());
        $this->assertSame(['admin'], $this->allowlist->getClientCredentials());
    }
}
