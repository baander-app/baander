<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\UserState;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Infrastructure\Security\UserProvider;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

final class UserProviderTest extends TestCase
{
    private UserRepositoryInterface&MockObject $userRepository;
    private UserProvider $provider;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->provider = new UserProvider(
            $this->userRepository,
            $this->createPasswordHasher(),
        );
    }

    private function createPasswordHasher(): \App\Auth\Infrastructure\Security\User\PasswordHasher
    {
        $factory = $this->createMock(
            \Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface::class,
        );
        $symfonyHasher = $this->createMock(
            \Symfony\Component\PasswordHasher\PasswordHasherInterface::class,
        );
        $factory->method('getPasswordHasher')->with(SecurityUser::class)->willReturn($symfonyHasher);

        return new \App\Auth\Infrastructure\Security\User\PasswordHasher($factory);
    }

    private function createDomainUser(string $email = 'test@example.com', string $password = 'hashed-pw'): User
    {
        return User::reconstitute(new UserState(
            id: Uuid::v4(),
            publicId: new \App\Shared\Domain\Model\PublicId(),
            name: 'Test User',
            email: $email,
            password: $password,
            totpSecret: null,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        ));
    }

    // --- supportsClass() ---

    public function testSupportsSecurityUserClass(): void
    {
        $this->assertTrue($this->provider->supportsClass(SecurityUser::class));
    }

    public function testDoesNotSupportOtherClass(): void
    {
        $this->assertFalse($this->provider->supportsClass(\stdClass::class));
        $this->assertFalse($this->provider->supportsClass('SomeOtherUserClass'));
    }

    // --- loadUserByIdentifier() ---

    public function testLoadsUserByEmail(): void
    {
        $email = 'test@example.com';
        $domainUser = $this->createDomainUser($email, 'hashed-pw');

        $this->userRepository
            ->expects($this->once())
            ->method('findByEmail')
            ->with($this->callback(fn (Email $e) => $e->toString() === $email))
            ->willReturn($domainUser);

        $securityUser = $this->provider->loadUserByIdentifier($email);

        $this->assertInstanceOf(SecurityUser::class, $securityUser);
        $this->assertSame($domainUser->getId()->toString(), $securityUser->getId());
        $this->assertSame($email, $securityUser->getEmail());
        $this->assertSame('hashed-pw', $securityUser->getPassword());
    }

    public function testThrowsWhenUserNotFoundByEmail(): void
    {
        $email = 'nonexistent@example.com';

        $this->userRepository
            ->method('findByEmail')
            ->willReturn(null);

        $this->expectException(UserNotFoundException::class);
        $this->expectExceptionMessage('User "nonexistent@example.com" not found.');

        $this->provider->loadUserByIdentifier($email);
    }

    public function testThrowsOnInvalidEmailFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->provider->loadUserByIdentifier('not-an-email');
    }

    // --- refreshUser() ---

    public function testRefreshesSecurityUser(): void
    {
        $domainUser = $this->createDomainUser('test@example.com', 'new-hash');
        $securityUser = new SecurityUser(
            $domainUser->getId()->toString(),
            'test@example.com',
            'old-hash',
        );

        $this->userRepository
            ->expects($this->once())
            ->method('findByUuid')
            ->with($domainUser->getId())
            ->willReturn($domainUser);

        $refreshed = $this->provider->refreshUser($securityUser);

        $this->assertInstanceOf(SecurityUser::class, $refreshed);
        $this->assertSame($domainUser->getId()->toString(), $refreshed->getId());
        $this->assertSame('new-hash', $refreshed->getPassword());
    }

    public function testThrowsWhenRefreshingNonSecurityUser(): void
    {
        $otherUser = $this->createMock(\Symfony\Component\Security\Core\User\UserInterface::class);

        $this->expectException(UnsupportedUserException::class);

        $this->provider->refreshUser($otherUser);
    }

    public function testThrowsWhenUserNotFoundDuringRefresh(): void
    {
        $userUuid = Uuid::v4();
        $securityUser = new SecurityUser($userUuid->toString(), 'test@example.com', 'hash');

        $this->userRepository
            ->method('findByUuid')
            ->willReturn(null);

        $this->expectException(UserNotFoundException::class);
        $this->expectExceptionMessage(sprintf('User with id "%s" not found.', $userUuid->toString()));

        $this->provider->refreshUser($securityUser);
    }

    // --- upgradePassword() ---

    public function testUpgradesPasswordForSecurityUser(): void
    {
        $domainUser = $this->createDomainUser('test@example.com', 'old-hash');
        $newHash = 'new-hashed-password';

        $securityUser = new SecurityUser(
            $domainUser->getId()->toString(),
            'test@example.com',
            'old-hash',
        );

        $this->userRepository
            ->method('findByUuid')
            ->with($domainUser->getId())
            ->willReturn($domainUser);

        $this->userRepository
            ->expects($this->once())
            ->method('save')
            ->with($domainUser);

        $this->provider->upgradePassword($securityUser, $newHash);

        // Verify password was actually changed on the domain model
        $this->assertSame($newHash, $domainUser->getPassword());
    }

    public function testUpgradePasswordDoesNothingForNonSecurityUser(): void
    {
        $otherUser = $this->createMock(\Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface::class);

        $this->userRepository
            ->expects($this->never())
            ->method('findByUuid');

        $this->provider->upgradePassword($otherUser, 'new-hash');
    }

    public function testUpgradePasswordDoesNothingWhenUserNotFound(): void
    {
        $userUuid = Uuid::v4();
        $securityUser = new SecurityUser($userUuid->toString(), 'test@example.com', 'old-hash');

        $this->userRepository
            ->method('findByUuid')
            ->willReturn(null);

        $this->userRepository
            ->expects($this->never())
            ->method('save');

        $this->provider->upgradePassword($securityUser, 'new-hash');
    }
}
