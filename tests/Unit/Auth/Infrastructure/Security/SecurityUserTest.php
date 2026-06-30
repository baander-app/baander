<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Infrastructure\Security\SecurityUser;
use PHPUnit\Framework\TestCase;

final class SecurityUserTest extends TestCase
{
    public function testGetters(): void
    {
        $user = new SecurityUser('uuid-123', 'test@example.com', 'hashed-pw', ['ROLE_USER', 'ROLE_ADMIN']);

        $this->assertSame('uuid-123', $user->getId());
        $this->assertSame('test@example.com', $user->getEmail());
        $this->assertSame('hashed-pw', $user->getPassword());
        $this->assertSame(['ROLE_USER', 'ROLE_ADMIN'], $user->getRoles());
        $this->assertSame('test@example.com', $user->getUserIdentifier());
    }

    public function testDefaultRole(): void
    {
        $user = new SecurityUser('id', 'e@e.com', 'pw');

        $this->assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testEraseCredentialsDoesNothing(): void
    {
        $user = new SecurityUser('id', 'e@e.com', 'pw');
        $user->eraseCredentials();

        $this->assertSame('pw', $user->getPassword());
    }
}
