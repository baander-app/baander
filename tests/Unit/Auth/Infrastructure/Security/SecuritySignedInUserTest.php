<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Infrastructure\Security\SecuritySignedInUser;
use App\Auth\Infrastructure\Security\SecurityUser;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class SecuritySignedInUserTest extends TestCase
{
    public function testTheSignedInUserIsNamedByItsIdNotItsEmailAddress(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser('0199bf3c-8a00-7000-8000-0000000000a1', 'admin@baander.app', 'hashed-pw'));

        self::assertSame('0199bf3c-8a00-7000-8000-0000000000a1', new SecuritySignedInUser($security)->id());
    }

    public function testNobodySignedInHasNoId(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        self::assertNull(new SecuritySignedInUser($security)->id());
    }
}
