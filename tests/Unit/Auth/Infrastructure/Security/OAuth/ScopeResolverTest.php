<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Infrastructure\Security\OAuth\ScopeResolver;
use PHPUnit\Framework\TestCase;

final class ScopeResolverTest extends TestCase
{
    private ScopeResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ScopeResolver();
    }

    public function testResolveValidScopes(): void
    {
        $scopes = $this->resolver->resolve(['access-api', 'admin', 'profile']);

        $this->assertCount(3, $scopes);
        $this->assertSame('access-api', $scopes[0]->toString());
        $this->assertSame('admin', $scopes[1]->toString());
    }

    public function testResolveSkipsInvalidScopes(): void
    {
        $scopes = $this->resolver->resolve(['access-api', '', 'INVALID', ' profile ']);

        // '' throws (empty), 'INVALID' → 'invalid' is valid lowercase, ' profile ' → 'profile' is valid
        $this->assertCount(3, $scopes);
        $this->assertSame(['access-api', 'invalid', 'profile'], array_map(fn ($s) => $s->toString(), $scopes));
    }

    public function testResolveEmptyArray(): void
    {
        $scopes = $this->resolver->resolve([]);

        $this->assertEmpty($scopes);
    }
}
