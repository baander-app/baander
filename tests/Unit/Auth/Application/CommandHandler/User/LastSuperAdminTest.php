<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\DeleteUserCommand;
use App\Auth\Application\Command\User\DisableUserCommand;
use App\Auth\Application\Command\User\SetUserRolesCommand;
use App\Auth\Application\CommandHandler\User\DeleteUserHandler;
use App\Auth\Application\CommandHandler\User\DisableUserHandler;
use App\Auth\Application\CommandHandler\User\SetUserRolesHandler;
use App\Auth\Application\Exception\LastSuperAdminException;
use App\Auth\Application\Service\SuperAdminContinuity;
use App\Auth\Application\Service\UserLookup;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Port\LiveConnectionsPortInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Deleting, disabling or demoting a user never leaves the install without an enabled super
 * admin, whether the admin API or a console command asks. The repository here keeps the stored
 * roles and disabled flag of each user, and its transaction keeps or discards the writes made in
 * it, so the refusal is judged on what is stored.
 *
 * @phpstan-type Removal 'delete'|'disable'|'demote'
 */
final class LastSuperAdminTest extends TestCase
{
    /** @var array<string, User> */
    private array $users = [];
    /** @var array<string, array{roles: list<string>, disabled: bool}> stored state by user ID */
    private array $stored = [];
    /** @var array<string, array{roles: list<string>, disabled: bool}>|null stored state when the open transaction began */
    private ?array $storedAtBegin = null;
    /** @var list<string> writes made inside the open transaction */
    private array $pending = [];
    /** @var list<string> writes that committed */
    private array $committed = [];

    /** @return iterable<string, array{Removal}> */
    public static function removals(): iterable
    {
        yield 'delete' => ['delete'];
        yield 'disable' => ['disable'];
        yield 'demote' => ['demote'];
    }

    /** @param Removal $removal */
    #[DataProvider('removals')]
    public function testTheOnlyEnabledSuperAdminIsNotRemoved(string $removal): void
    {
        $this->superAdmin('root@baander.app');

        try {
            $this->remove($removal, 'root@baander.app');
            self::fail('The only enabled super admin must stay.');
        } catch (LastSuperAdminException $exception) {
            self::assertSame(['reason' => 'last_super_admin'], $exception->details);
            self::assertSame('This user is the only enabled super admin. Make another user a super admin first.', $exception->getMessage());
        }

        self::assertSame([], $this->committed);
        self::assertSame(['roles' => ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], 'disabled' => false], $this->storedState('root@baander.app'));
    }

    /** @param Removal $removal */
    #[DataProvider('removals')]
    public function testADisabledSuperAdminDoesNotCountAsAnotherOne(string $removal): void
    {
        $this->superAdmin('root@baander.app');
        $this->superAdmin('former@baander.app', disabled: true);

        $this->expectException(LastSuperAdminException::class);

        $this->remove($removal, 'root@baander.app');
    }

    /** @param Removal $removal */
    #[DataProvider('removals')]
    public function testWithAnotherEnabledSuperAdminTheRemovalCommits(string $removal): void
    {
        $this->superAdmin('root@baander.app');
        $this->superAdmin('second@baander.app');

        $this->remove($removal, 'second@baander.app');

        self::assertCount(1, $this->committed);
        self::assertStringEndsWith('second@baander.app', $this->committed[0]);
    }

    public function testOnceOneOfTwoSuperAdminsIsDemotedTheOtherIsTheLastOne(): void
    {
        $this->superAdmin('root@baander.app');
        $this->superAdmin('second@baander.app');

        $this->remove('demote', 'second@baander.app');

        $this->expectException(LastSuperAdminException::class);

        $this->remove('demote', 'root@baander.app');
    }

    /** @param Removal $removal */
    #[DataProvider('removals')]
    public function testAnInstallWithoutASuperAdminStillRemovesAnAdmin(string $removal): void
    {
        $this->user('admin@baander.app', ['ROLE_USER', 'ROLE_ADMIN']);

        $this->remove($removal, 'admin@baander.app');

        self::assertCount(1, $this->committed);
    }

    /** @return iterable<string, array{Removal}> */
    public static function removalsOfADisabledUser(): iterable
    {
        yield 'delete' => ['delete'];
        yield 'demote' => ['demote'];
    }

    /** @param Removal $removal */
    #[DataProvider('removalsOfADisabledUser')]
    public function testADisabledSuperAdminIsRemovedWhenNoSuperAdminIsEnabled(string $removal): void
    {
        $this->superAdmin('former@baander.app', disabled: true);

        $this->remove($removal, 'former@baander.app');

        self::assertCount(1, $this->committed);
    }

    public function testTheOnlySuperAdminCanChangeTheirOtherRolesAndStayASuperAdmin(): void
    {
        $this->superAdmin('root@baander.app');

        $this->rolesHandler()(new SetUserRolesCommand('root@baander.app', ['ROLE_SUPER_ADMIN']));

        self::assertSame(['roles' => ['ROLE_SUPER_ADMIN'], 'disabled' => false], $this->storedState('root@baander.app'));
    }

    /** @param Removal $removal */
    private function remove(string $removal, string $email): void
    {
        match ($removal) {
            'delete' => $this->deleteHandler()(new DeleteUserCommand($email)),
            'disable' => $this->disableHandler()(new DisableUserCommand($email)),
            'demote' => $this->rolesHandler()(new SetUserRolesCommand($email, ['ROLE_USER'])),
        };
    }

    private function superAdmin(string $email, bool $disabled = false): void
    {
        $this->user($email, ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], $disabled);
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles, bool $disabled = false): void
    {
        $user = User::createByOperator(new Email($email), 'hashed-password', 'Operator', $roles);
        if ($disabled) {
            $user->disable();
        }
        $this->users[$email] = $user;
        $this->store($user);
    }

    /** @return array{roles: list<string>, disabled: bool} */
    private function storedState(string $email): array
    {
        return $this->stored[$this->users[$email]->getId()->toString()];
    }

    private function store(User $user): void
    {
        $this->stored[$user->getId()->toString()] = [
            'roles' => array_values($user->getRoles()),
            'disabled' => $user->isDisabled(),
        ];
    }

    private function rolesHandler(): SetUserRolesHandler
    {
        $users = $this->userRepository();

        return new SetUserRolesHandler(new UserLookup($users), $users, $this->transaction(), new SuperAdminContinuity($users));
    }

    private function deleteHandler(): DeleteUserHandler
    {
        $users = $this->userRepository();

        return new DeleteUserHandler(
            new UserLookup($users),
            $users,
            $this->transaction(),
            new SuperAdminContinuity($users),
            $this->createStub(LiveConnectionsPortInterface::class),
            $this->createStub(LoggerInterface::class),
        );
    }

    private function disableHandler(): DisableUserHandler
    {
        $users = $this->userRepository();

        return new DisableUserHandler(
            new UserLookup($users),
            $users,
            $this->createStub(AccessTokenRepositoryInterface::class),
            $this->createStub(RefreshTokenRepositoryInterface::class),
            $this->transaction(),
            new SuperAdminContinuity($users),
            $this->createStub(LiveConnectionsPortInterface::class),
            $this->createStub(LoggerInterface::class),
        );
    }

    private function userRepository(): UserRepositoryInterface
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByEmail')->willReturnCallback(fn (Email $email): ?User => $this->users[$email->toString()] ?? null);
        $users->method('findByPublicId')->willReturnCallback(
            fn (PublicId $id): ?User => array_find($this->users, static fn (User $user): bool => $user->getPublicId()->equals($id)),
        );
        $users->method('save')->willReturnCallback(function (User $user): void {
            $this->store($user);
            $this->write('save ' . $user->getEmail());
        });
        $users->method('delete')->willReturnCallback(function (Uuid $id): void {
            $email = array_find_key($this->users, static fn (User $user): bool => $user->getId()->equals($id));
            unset($this->stored[$id->toString()]);
            $this->write('delete ' . $email);
        });
        $users->method('lockSuperAdminRemovals')->willReturnCallback(function (): void {
            if ($this->storedAtBegin === null) {
                throw new \LogicException('The lock holds only inside a transaction.');
            }
        });
        $users->method('soleEnabledSuperAdmin')->willReturnCallback(function (): ?Uuid {
            $enabled = array_keys(array_filter(
                $this->stored,
                static fn (array $state): bool => in_array('ROLE_SUPER_ADMIN', $state['roles'], true) && !$state['disabled'],
            ));

            return count($enabled) === 1 ? Uuid::fromString((string) $enabled[0]) : null;
        });

        return $users;
    }

    private function write(string $write): void
    {
        if ($this->storedAtBegin === null) {
            $this->committed[] = $write;

            return;
        }
        $this->pending[] = $write;
    }

    private function transaction(): TransactionPortInterface
    {
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(function (callable $operation): mixed {
            $this->storedAtBegin = $this->stored;
            try {
                $result = $operation();
            } catch (\Throwable $exception) {
                $this->stored = $this->storedAtBegin;
                $this->pending = [];
                throw $exception;
            } finally {
                $this->storedAtBegin = null;
            }
            $this->committed = [...$this->committed, ...$this->pending];
            $this->pending = [];

            return $result;
        });

        return $transaction;
    }
}
