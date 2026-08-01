<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Domain\Model;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\UserState;
use App\Shared\Domain\Model\Email;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testRegisterCreatesUser(): void
    {
        $user = User::register(new Email('test@example.com'), 'hashed-pw', 'Alice');

        $this->assertSame('Alice', $user->getName());
        $this->assertSame('test@example.com', $user->getEmail());
        $this->assertSame('hashed-pw', $user->getPassword());
        $this->assertFalse($user->isEmailVerified());
        $this->assertNull($user->getEmailVerifiedAt());
        $this->assertNull($user->getTotpSecret());
    }

    public function testRegisterThrowsOnEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        User::register(new Email('test@example.com'), 'hashed-pw', '  ');
    }

    public function testReconstituteRestoresAllFields(): void
    {
        $now = new \DateTimeImmutable();
        $verifiedAt = new \DateTimeImmutable('+1 minute');
        $user = User::reconstitute(new UserState(
            id: \App\Shared\Domain\Model\Uuid::v4(),
            publicId: \App\Shared\Domain\Model\PublicId::fromString('usr_abc123def456ghjkl'),
            name: 'Bob',
            email: 'bob@example.com',
            password: 'hashed',
            totpSecret: null,
            createdAt: $now,
            updatedAt: $now,
            emailVerifiedAt: $verifiedAt,
            roles: ['ROLE_USER'],
        ));

        $this->assertTrue($user->isEmailVerified());
        $this->assertEquals($verifiedAt, $user->getEmailVerifiedAt());
    }

    public function testReconstituteWithNullEmailVerifiedAt(): void
    {
        $user = User::reconstitute(new UserState(
            id: \App\Shared\Domain\Model\Uuid::v4(),
            publicId: \App\Shared\Domain\Model\PublicId::fromString('usr_abc123def456ghjkl'),
            name: 'Bob',
            email: 'bob@example.com',
            password: 'hashed',
            totpSecret: null,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            roles: ['ROLE_USER'],
        ));

        $this->assertFalse($user->isEmailVerified());
    }

    public function testVerifyEmailMarksAsVerified(): void
    {
        $user = User::register(new Email('test@example.com'), 'hashed-pw', 'Alice');

        $user->verifyEmail();

        $this->assertTrue($user->isEmailVerified());
        $this->assertNotNull($user->getEmailVerifiedAt());
    }

    public function testVerifyEmailIdempotent(): void
    {
        $user = User::register(new Email('test@example.com'), 'hashed-pw', 'Alice');
        $user->verifyEmail();
        $before = $user->getEmailVerifiedAt();

        $user->verifyEmail();

        $this->assertEquals($before, $user->getEmailVerifiedAt());
    }

    public function testChangePassword(): void
    {
        $user = User::register(new Email('test@example.com'), 'old-hash', 'Alice');

        $user->changePassword('new-hash');

        $this->assertSame('new-hash', $user->getPassword());
    }

    public function testChangePasswordSameValueIsNoOp(): void
    {
        $user = User::register(new Email('test@example.com'), 'same-hash', 'Alice');
        $before = $user->getUpdatedAt();

        $user->changePassword('same-hash');

        $this->assertEquals($before, $user->getUpdatedAt());
    }

    public function testUpdateName(): void
    {
        $user = User::register(new Email('test@example.com'), 'hashed-pw', 'Alice');

        $user->updateName('Bob');

        $this->assertSame('Bob', $user->getName());
    }

    public function testUpdateNameThrowsOnEmpty(): void
    {
        $user = User::register(new Email('test@example.com'), 'hashed-pw', 'Alice');

        $this->expectException(InvalidArgumentException::class);

        $user->updateName('');
    }

    public function testSetTotpSecret(): void
    {
        $user = User::register(new Email('test@example.com'), 'hashed-pw', 'Alice');

        $user->setTotpSecret('secret123');

        $this->assertSame('secret123', $user->getTotpSecret());
    }

    public function testGettersReturnExpectedTypes(): void
    {
        $user = User::register(new Email('test@example.com'), 'hashed-pw', 'Alice');

        $this->assertInstanceOf(\App\Shared\Domain\Model\Uuid::class, $user->getId());
        $this->assertInstanceOf(\App\Shared\Domain\Model\PublicId::class, $user->getPublicId());
        $this->assertInstanceOf(\DateTimeImmutable::class, $user->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $user->getUpdatedAt());
    }

    public function testRegisterAssignsUserRoleByDefault(): void
    {
        $user = User::register(new Email('test@example.com'), 'hashed-pw', 'Alice');

        $this->assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testCreateByOperatorCreatesAdminWithEmailVerified(): void
    {
        $user = User::createByOperator(
            new Email('admin@example.com'),
            'hashed-pw',
            'Admin',
            ['ROLE_ADMIN'],
        );

        $this->assertSame('Admin', $user->getName());
        $this->assertSame('admin@example.com', $user->getEmail());
        $this->assertSame(['ROLE_ADMIN'], $user->getRoles());
        $this->assertTrue($user->isEmailVerified());
        $this->assertNotNull($user->getEmailVerifiedAt());
    }

    public function testCreateByOperatorCreatesUserWithEmailVerified(): void
    {
        $user = User::createByOperator(
            new Email('user@example.com'),
            'hashed-pw',
            'User',
            ['ROLE_USER'],
        );

        $this->assertSame(['ROLE_USER'], $user->getRoles());
        $this->assertTrue($user->isEmailVerified());
    }

    public function testCreateByOperatorThrowsOnEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        User::createByOperator(
            new Email('test@example.com'),
            'hashed-pw',
            '  ',
            ['ROLE_USER'],
        );
    }

    public function testHasRoleReturnsTrueForDirectlyAssignedRole(): void
    {
        $user = User::createByOperator(
            new Email('admin@example.com'),
            'hashed-pw',
            'Admin',
            ['ROLE_ADMIN'],
        );

        $this->assertTrue($user->hasRole('ROLE_ADMIN'));
    }

    public function testHasRoleResolvesHierarchy(): void
    {
        $user = User::createByOperator(
            new Email('admin@example.com'),
            'hashed-pw',
            'Admin',
            ['ROLE_ADMIN'],
        );

        $this->assertTrue($user->hasRole('ROLE_USER'));
    }

    public function testHasRoleReturnsFalseForNonexistentRole(): void
    {
        $user = User::createByOperator(
            new Email('user@example.com'),
            'hashed-pw',
            'User',
            ['ROLE_USER'],
        );

        $this->assertFalse($user->hasRole('ROLE_NONEXISTENT'));
    }

    public function testHasRoleReturnsFalseForRoleAboveInHierarchy(): void
    {
        $user = User::createByOperator(
            new Email('user@example.com'),
            'hashed-pw',
            'User',
            ['ROLE_USER'],
        );

        $this->assertFalse($user->hasRole('ROLE_ADMIN'));
    }
}
