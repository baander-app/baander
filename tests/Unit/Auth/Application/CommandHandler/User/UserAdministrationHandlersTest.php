<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\DeleteUserCommand;
use App\Auth\Application\Command\User\RenameUserCommand;
use App\Auth\Application\Command\User\SetUserRolesCommand;
use App\Auth\Application\CommandHandler\User\DeleteUserHandler;
use App\Auth\Application\CommandHandler\User\RenameUserHandler;
use App\Auth\Application\CommandHandler\User\SetUserRolesHandler;
use App\Auth\Application\Query\User\ListUsersQuery;
use App\Auth\Application\QueryHandler\User\ListUsersHandler;
use App\Auth\Application\Service\UserLookup;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The rename, delete, role and list use cases behind the admin panel and the app:user:* commands. */
final class UserAdministrationHandlersTest extends TestCase
{
    private User $user;
    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->user = User::register(new Email('member@baander.app'), 'hashed-password', 'Member');
    }

    public function testRenamingSavesTheNewName(): void
    {
        $renamed = $this->renameHandler()(new RenameUserCommand('member@baander.app', 'Renamed Member'));

        self::assertSame($this->user, $renamed);
        self::assertSame('Renamed Member', $this->user->getName());
        self::assertSame(['save'], $this->log);
    }

    public function testRenamingToTheCurrentNameSucceedsWithoutChange(): void
    {
        $this->renameHandler()(new RenameUserCommand($this->user->getId()->toString(), 'Member'));

        self::assertSame([], $this->log);
    }

    /** @return iterable<string, array{string, string}> */
    public static function rejectedNames(): iterable
    {
        yield 'empty' => ['', RenameUserHandler::BLANK_NAME];
        yield 'spaces only' => ['   ', RenameUserHandler::BLANK_NAME];
        yield '256 characters' => [str_repeat('æ', 256), RenameUserHandler::NAME_TOO_LONG];
    }

    #[DataProvider('rejectedNames')]
    public function testRenamingRejectsABlankOrTooLongName(string $name, string $message): void
    {
        try {
            $this->renameHandler()(new RenameUserCommand('member@baander.app', $name));
            self::fail('The name must be rejected.');
        } catch (InvalidInputException $exception) {
            self::assertSame($message, $exception->getMessage());
            self::assertSame(['name' => [$message]], $exception->details);
        }

        self::assertSame('Member', $this->user->getName());
        self::assertSame([], $this->log);
    }

    public function testRenamingAcceptsA255CharacterName(): void
    {
        $this->renameHandler()(new RenameUserCommand('member@baander.app', str_repeat('æ', 255)));

        self::assertSame(str_repeat('æ', 255), $this->user->getName());
    }

    public function testSettingRolesReplacesThemAndSaves(): void
    {
        $user = $this->rolesHandler()(new SetUserRolesCommand('member@baander.app', ['ROLE_USER', 'ROLE_ADMIN']));

        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $user->getRoles());
        self::assertSame(['save'], $this->log);
    }

    public function testSettingTheCurrentRolesSucceedsWithoutChange(): void
    {
        $this->rolesHandler()(new SetUserRolesCommand('member@baander.app', ['ROLE_USER']));

        self::assertSame([], $this->log);
    }

    public function testSettingAnUnknownRoleIsInvalidInput(): void
    {
        try {
            $this->rolesHandler()(new SetUserRolesCommand('member@baander.app', ['ROLE_OWNER']));
            self::fail('An unknown role must be rejected.');
        } catch (InvalidInputException $exception) {
            self::assertStringContainsString('ROLE_OWNER', $exception->getMessage());
        }

        self::assertSame(['ROLE_USER'], $this->user->getRoles());
        self::assertSame([], $this->log);
    }

    public function testDeletingRemovesTheNamedUser(): void
    {
        $this->deleteHandler()(new DeleteUserCommand($this->user->getId()->toString()));

        self::assertSame(['delete ' . $this->user->getId()->toString()], $this->log);
    }

    public function testAnUnknownUserIsNotFoundAndNothingChanges(): void
    {
        foreach ([
            fn () => $this->renameHandler()(new RenameUserCommand('nobody@baander.app', 'Nobody')),
            fn () => $this->rolesHandler()(new SetUserRolesCommand(Uuid::generate()->toString(), ['ROLE_ADMIN'])),
            fn () => $this->deleteHandler()(new DeleteUserCommand('not-a-uuid')),
        ] as $action) {
            try {
                $action();
                self::fail('An unknown user must not be found.');
            } catch (NotFoundException) {
            }
        }

        self::assertSame([], $this->log);
    }

    public function testListingPassesTheFiltersAndPage(): void
    {
        $page = $this->listHandler()(new ListUsersQuery('ROLE_ADMIN', true, 10, 20));

        self::assertSame([$this->user], $page->users);
        self::assertSame(42, $page->total);
        self::assertSame(['findAll ROLE_ADMIN 1 10 20', 'count ROLE_ADMIN 1'], $this->log);
    }

    /** @return iterable<string, array{ListUsersQuery}> */
    public static function rejectedListings(): iterable
    {
        yield 'unknown role' => [new ListUsersQuery(role: 'ROLE_OWNER')];
        yield 'limit 0' => [new ListUsersQuery(limit: 0)];
        yield 'limit above the maximum' => [new ListUsersQuery(limit: ListUsersQuery::MAX_LIMIT + 1)];
        yield 'negative offset' => [new ListUsersQuery(offset: -1)];
    }

    #[DataProvider('rejectedListings')]
    public function testListingRejectsAnUnknownRoleOrAPageOutOfRange(ListUsersQuery $query): void
    {
        $this->expectException(InvalidInputException::class);

        $this->listHandler()($query);
    }

    private function renameHandler(): RenameUserHandler
    {
        $users = $this->userRepository();

        return new RenameUserHandler(new UserLookup($users), $users);
    }

    private function rolesHandler(): SetUserRolesHandler
    {
        $users = $this->userRepository();

        return new SetUserRolesHandler(new UserLookup($users), $users);
    }

    private function deleteHandler(): DeleteUserHandler
    {
        $users = $this->userRepository();

        return new DeleteUserHandler(new UserLookup($users), $users);
    }

    private function listHandler(): ListUsersHandler
    {
        return new ListUsersHandler($this->userRepository());
    }

    private function userRepository(): UserRepositoryInterface
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByUuid')->willReturnCallback(
            fn (Uuid $id): ?User => $id->equals($this->user->getId()) ? $this->user : null,
        );
        $users->method('findByEmail')->willReturnCallback(
            fn (Email $email): ?User => $email->toString() === $this->user->getEmail() ? $this->user : null,
        );
        $users->method('save')->willReturnCallback(function (User $user): void {
            self::assertSame($this->user, $user);
            $this->log[] = 'save';
        });
        $users->method('delete')->willReturnCallback(function (Uuid $id): void {
            $this->log[] = 'delete ' . $id->toString();
        });
        $users->method('findAll')->willReturnCallback(function (?string $role, ?bool $disabled, int $limit, int $offset): array {
            $this->log[] = sprintf('findAll %s %d %d %d', $role, $disabled, $limit, $offset);

            return [$this->user];
        });
        $users->method('count')->willReturnCallback(function (?string $role, ?bool $disabled): int {
            $this->log[] = sprintf('count %s %d', $role, $disabled);

            return 42;
        });

        return $users;
    }
}
