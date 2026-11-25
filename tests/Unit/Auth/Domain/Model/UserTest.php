<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Domain\Model;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\UserState;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testRegisterCreatesUser(): void
    {
        $user = User::register(new Email('test@baander.app'), 'hashed-pw', 'Alice');

        $this->assertSame('Alice', $user->getName());
        $this->assertSame('test@baander.app', $user->getEmail());
        $this->assertSame('hashed-pw', $user->getPassword());
        $this->assertFalse($user->isEmailVerified());
        $this->assertNull($user->getEmailVerifiedAt());
        $this->assertFalse($user->hasTotp());
    }

    public function testRegisterThrowsOnEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        User::register(new Email('test@baander.app'), 'hashed-pw', '  ');
    }

    public function testReconstituteRestoresAllFields(): void
    {
        $now = new DateTimeImmutable();
        $verifiedAt = new DateTimeImmutable('+1 minute');
        $user = User::reconstitute(new UserState(
            id: Uuid::v4(),
            publicId: PublicId::fromString('usr_abc123def456ghjkl'),
            name: 'Bob',
            email: 'bob@baander.app',
            password: 'hashed',
            totpSecretCiphertext: null,
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
            id: Uuid::v4(),
            publicId: PublicId::fromString('usr_abc123def456ghjkl'),
            name: 'Bob',
            email: 'bob@baander.app',
            password: 'hashed',
            totpSecretCiphertext: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            roles: ['ROLE_USER'],
        ));

        $this->assertFalse($user->isEmailVerified());
    }

    public function testVerifyEmailMarksAsVerified(): void
    {
        $user = User::register(new Email('test@baander.app'), 'hashed-pw', 'Alice');

        $user->verifyEmail();

        $this->assertTrue($user->isEmailVerified());
        $this->assertNotNull($user->getEmailVerifiedAt());
    }

    public function testVerifyEmailIdempotent(): void
    {
        $user = User::register(new Email('test@baander.app'), 'hashed-pw', 'Alice');
        $user->verifyEmail();
        $before = $user->getEmailVerifiedAt();

        $user->verifyEmail();

        $this->assertEquals($before, $user->getEmailVerifiedAt());
    }

    public function testChangePassword(): void
    {
        $user = User::register(new Email('test@baander.app'), 'old-hash', 'Alice');

        $user->changePassword('new-hash');

        $this->assertSame('new-hash', $user->getPassword());
    }

    public function testChangePasswordSameValueIsNoOp(): void
    {
        $user = User::register(new Email('test@baander.app'), 'same-hash', 'Alice');
        $before = $user->getUpdatedAt();

        $user->changePassword('same-hash');

        $this->assertEquals($before, $user->getUpdatedAt());
    }

    public function testUpdateName(): void
    {
        $user = User::register(new Email('test@baander.app'), 'hashed-pw', 'Alice');

        $user->updateName('Bob');

        $this->assertSame('Bob', $user->getName());
    }

    public function testUpdateNameThrowsOnEmpty(): void
    {
        $user = User::register(new Email('test@baander.app'), 'hashed-pw', 'Alice');

        $this->expectException(InvalidArgumentException::class);

        $user->updateName('');
    }

    public function testReplaceRolesSetsTheGivenRolesEachOnce(): void
    {
        $user = User::register(new Email('test@baander.app'), 'hashed-pw', 'Alice');
        $before = $user->getUpdatedAt();

        $user->replaceRoles(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN', 'ROLE_ADMIN']);

        $this->assertSame(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], $user->getRoles());
        $this->assertGreaterThanOrEqual($before, $user->getUpdatedAt());
        $this->assertNotSame($before, $user->getUpdatedAt());
    }

    public function testReplaceRolesWithTheCurrentRolesInAnyOrderChangesNothing(): void
    {
        $user = User::createByOperator(new Email('test@baander.app'), 'hashed-pw', 'Alice', ['ROLE_USER', 'ROLE_ADMIN']);
        $before = $user->getUpdatedAt();

        $user->replaceRoles(['ROLE_ADMIN', 'ROLE_USER']);

        $this->assertSame(['ROLE_USER', 'ROLE_ADMIN'], $user->getRoles());
        $this->assertSame($before, $user->getUpdatedAt());
    }

    /** @return iterable<string, array{list<string>}> */
    public static function rejectedRoleSets(): iterable
    {
        yield 'no role' => [[]];
        yield 'unknown role' => [['ROLE_USER', 'ROLE_OWNER']];
    }

    /** @param list<string> $roles */
    #[DataProvider('rejectedRoleSets')]
    public function testReplaceRolesRejectsAnEmptyOrUnknownRoleSetAndKeepsTheRoles(array $roles): void
    {
        $user = User::register(new Email('test@baander.app'), 'hashed-pw', 'Alice');

        try {
            $user->replaceRoles($roles);
            $this->fail('The role set must be rejected.');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testTotpIsOnExactlyWhileASealedSecretIsStored(): void
    {
        $user = User::register(new Email('test@baander.app'), 'hashed-pw', 'Alice');

        $user->enableTotp('sealed-secret');
        $this->assertTrue($user->hasTotp());
        $this->assertSame('sealed-secret', $user->getTotpSecretCiphertext());

        $user->disableTotp();
        $this->assertFalse($user->hasTotp());
        $this->assertNull($user->getTotpSecretCiphertext());
    }

    public function testARequestedEmailChangeStaysPendingAndLeavesTheAddressAlone(): void
    {
        $user = User::createByOperator(new Email('alice@baander.app'), 'hashed-pw', 'Alice', ['ROLE_USER']);

        $user->requestEmailChange(new Email('Alice.New@baander.app'));

        $this->assertSame('alice@baander.app', $user->getEmail());
        $this->assertTrue($user->isEmailVerified());
        $this->assertSame('alice.new@baander.app', $user->getPendingEmail());
    }

    public function testRequestingTheCurrentAddressDropsAPendingChange(): void
    {
        $user = User::register(new Email('alice@baander.app'), 'hashed-pw', 'Alice');
        $user->requestEmailChange(new Email('alice.new@baander.app'));

        $user->requestEmailChange(new Email('ALICE@baander.app'));

        $this->assertNull($user->getPendingEmail());
    }

    public function testConfirmingThePendingAddressMakesItTheVerifiedAddress(): void
    {
        $user = User::register(new Email('alice@baander.app'), 'hashed-pw', 'Alice');
        $user->requestEmailChange(new Email('alice.new@baander.app'));

        $user->confirmPendingEmail();

        $this->assertSame('alice.new@baander.app', $user->getEmail());
        $this->assertTrue($user->isEmailVerified());
        $this->assertNull($user->getPendingEmail());
    }

    public function testConfirmingWithoutAPendingAddressIsRefused(): void
    {
        $user = User::register(new Email('alice@baander.app'), 'hashed-pw', 'Alice');

        $this->expectException(\LogicException::class);

        $user->confirmPendingEmail();
    }

    public function testAnOperatorEmailChangeIsVerifiedAndDropsAPendingChange(): void
    {
        $user = User::register(new Email('alice@baander.app'), 'hashed-pw', 'Alice');
        $user->requestEmailChange(new Email('alice.pending@baander.app'));

        $user->assignVerifiedEmail(new Email('alice.new@baander.app'));

        $this->assertSame('alice.new@baander.app', $user->getEmail());
        $this->assertTrue($user->isEmailVerified());
        $this->assertNull($user->getPendingEmail());
    }

    public function testAResetToANeverVerifiedAddressVerifiesItAndTurnsTotpOff(): void
    {
        $user = User::register(new Email('alice@baander.app'), 'hashed-pw', 'Alice');
        $user->enableTotp('sealed-secret');
        $user->requestEmailChange(new Email('alice.pending@baander.app'));

        $user->claimByPasswordReset();

        $this->assertTrue($user->isEmailVerified());
        $this->assertFalse($user->hasTotp());
        $this->assertNull($user->getPendingEmail());
        $this->assertSame('alice@baander.app', $user->getEmail());
    }

    public function testGettersReturnExpectedTypes(): void
    {
        $user = User::register(new Email('test@baander.app'), 'hashed-pw', 'Alice');

        $this->assertInstanceOf(Uuid::class, $user->getId());
        $this->assertInstanceOf(PublicId::class, $user->getPublicId());
        $this->assertInstanceOf(DateTimeImmutable::class, $user->getCreatedAt());
        $this->assertInstanceOf(DateTimeImmutable::class, $user->getUpdatedAt());
    }

    public function testRegisterAssignsUserRoleByDefault(): void
    {
        $user = User::register(new Email('test@baander.app'), 'hashed-pw', 'Alice');

        $this->assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testCreateByOperatorCreatesAdminWithEmailVerified(): void
    {
        $user = User::createByOperator(
            new Email('admin@baander.app'),
            'hashed-pw',
            'Admin',
            ['ROLE_ADMIN'],
        );

        $this->assertSame('Admin', $user->getName());
        $this->assertSame('admin@baander.app', $user->getEmail());
        $this->assertSame(['ROLE_ADMIN'], $user->getRoles());
        $this->assertTrue($user->isEmailVerified());
        $this->assertNotNull($user->getEmailVerifiedAt());
    }

    public function testCreateByOperatorCreatesUserWithEmailVerified(): void
    {
        $user = User::createByOperator(
            new Email('user@baander.app'),
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
            new Email('test@baander.app'),
            'hashed-pw',
            '  ',
            ['ROLE_USER'],
        );
    }

    public function testHasRoleReturnsTrueForDirectlyAssignedRole(): void
    {
        $user = User::createByOperator(
            new Email('admin@baander.app'),
            'hashed-pw',
            'Admin',
            ['ROLE_ADMIN'],
        );

        $this->assertTrue($user->hasRole('ROLE_ADMIN'));
    }

    public function testHasRoleResolvesHierarchy(): void
    {
        $user = User::createByOperator(
            new Email('admin@baander.app'),
            'hashed-pw',
            'Admin',
            ['ROLE_ADMIN'],
        );

        $this->assertTrue($user->hasRole('ROLE_USER'));
    }

    public function testHasRoleReturnsFalseForNonexistentRole(): void
    {
        $user = User::createByOperator(
            new Email('user@baander.app'),
            'hashed-pw',
            'User',
            ['ROLE_USER'],
        );

        $this->assertFalse($user->hasRole('ROLE_NONEXISTENT'));
    }

    public function testHasRoleReturnsFalseForRoleAboveInHierarchy(): void
    {
        $user = User::createByOperator(
            new Email('user@baander.app'),
            'hashed-pw',
            'User',
            ['ROLE_USER'],
        );

        $this->assertFalse($user->hasRole('ROLE_ADMIN'));
    }
}
