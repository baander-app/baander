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
        $this->allowlist = new ScopeAllowlist(['profile', 'email', 'library', 'playlist']);
    }

    public function testFilterKeepsAllowedScopes(): void
    {
        $this->assertSame(['profile', 'email', 'library'], $this->allowlist->filter(['profile', 'email', 'library']));
    }

    public function testFilterDropsDisallowedScopes(): void
    {
        $this->assertSame(['profile'], $this->allowlist->filter(['profile', 'admin', 'nonexistent']));
    }

    public function testFilterReturnsEmptyWhenNoScopesMatch(): void
    {
        $this->assertSame([], $this->allowlist->filter(['admin', 'nonexistent']));
    }

    public function testFilterPreservesOrder(): void
    {
        $this->assertSame(['playlist', 'email', 'profile'], $this->allowlist->filter(['playlist', 'email', 'profile']));
    }

    public function testFilterHandlesEmptyArray(): void
    {
        $this->assertSame([], $this->allowlist->filter([]));
    }

    public function testGetScopesReturnsTheAllowlist(): void
    {
        $this->assertSame(['profile', 'email', 'library', 'playlist'], $this->allowlist->getScopes());
    }
}
