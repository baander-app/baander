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
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

final class UserProviderTest extends TestCase
{
    private UserRepositoryInterface&Stub $userRepository;
    private UserProvider $provider;

    protected function setUp(): void
    {
        $this->userRepository = $this->createStub(UserRepositoryInterface::class);
        $this->provider = $this->createUserProviderFixture();
    }

    private function createUserProviderFixture(): UserProvider
    {
        $fixture = new UserProvider(
            $this->userRepository,
        );
        return $fixture;
    }

    private function createDomainUser(string $email = 'test@baander.app', string $password = 'hashed-pw'): User
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
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->provider = $this->createUserProviderFixture();

        $email = 'test@baander.app';
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
        $email = 'nonexistent@baander.app';

        $this->userRepository
            ->method('findByEmail')
            ->willReturn(null);

        $this->expectException(UserNotFoundException::class);
        $this->expectExceptionMessage('User "nonexistent@baander.app" not found.');

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
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->provider = $this->createUserProviderFixture();

        $domainUser = $this->createDomainUser('test@baander.app', 'new-hash');
        $securityUser = new SecurityUser(
            $domainUser->getId()->toString(),
            'test@baander.app',
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
        $otherUser = $this->createStub(\Symfony\Component\Security\Core\User\UserInterface::class);

        $this->expectException(UnsupportedUserException::class);

        $this->provider->refreshUser($otherUser);
    }

    public function testThrowsWhenUserNotFoundDuringRefresh(): void
    {
        $userUuid = Uuid::v4();
        $securityUser = new SecurityUser($userUuid->toString(), 'test@baander.app', 'hash');

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
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->provider = $this->createUserProviderFixture();

        $domainUser = $this->createDomainUser('test@baander.app', 'old-hash');
        $newHash = 'new-hashed-password';

        $securityUser = new SecurityUser(
            $domainUser->getId()->toString(),
            'test@baander.app',
            'old-hash',
        );

        $this->userRepository
            ->expects($this->once())
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
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->provider = $this->createUserProviderFixture();

        $otherUser = $this->createStub(\Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface::class);

        $this->userRepository
            ->expects($this->never())
            ->method('findByUuid');

        $this->provider->upgradePassword($otherUser, 'new-hash');
    }

    public function testUpgradePasswordDoesNothingWhenUserNotFound(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->provider = $this->createUserProviderFixture();

        $userUuid = Uuid::v4();
        $securityUser = new SecurityUser($userUuid->toString(), 'test@baander.app', 'old-hash');

        $this->userRepository
            ->method('findByUuid')
            ->willReturn(null);

        $this->userRepository
            ->expects($this->never())
            ->method('save');

        $this->provider->upgradePassword($securityUser, 'new-hash');
    }
}
