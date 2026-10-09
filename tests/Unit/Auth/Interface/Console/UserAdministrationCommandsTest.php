<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Console;

use App\Auth\Application\Command\User\DeleteUserCommand;
use App\Auth\Application\Command\User\DisableUserCommand as DisableUserMessage;
use App\Auth\Application\Command\User\EnableUserCommand as EnableUserMessage;
use App\Auth\Application\Command\User\RenameUserCommand;
use App\Auth\Application\Command\User\SetUserRolesCommand;
use App\Auth\Application\DTO\UserPage;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Query\User\ListUsersQuery;
use App\Auth\Domain\Model\User;
use App\Auth\Interface\Console\DisableUserCommand;
use App\Auth\Interface\Console\EnableUserCommand;
use App\Auth\Interface\Console\UserDeleteCommand;
use App\Auth\Interface\Console\UserListCommand;
use App\Auth\Interface\Console\UserRenameCommand;
use App\Auth\Interface\Console\UserRolesCommand;
use App\Auth\Interface\Resource\AdminUserResource;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Domain\Model\Email;
use App\Shared\Interface\Console\AdminCommandSupport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/** app:user:list, app:user:rename, app:user:delete, app:user:roles, app:user:disable and app:user:enable dispatch the admin API's use cases. */
final class UserAdministrationCommandsTest extends TestCase
{
    /** @var list<object> */
    private array $dispatched = [];
    private ?\Throwable $failure = null;
    private User $user;

    protected function setUp(): void
    {
        $this->user = User::createByOperator(new Email('admin@baander.app'), 'hashed', 'Admin', ['ROLE_USER', 'ROLE_ADMIN']);
    }

    public function testListPassesTheFiltersAndPrintsTheApiDataWithJson(): void
    {
        $tester = new CommandTester(new UserListCommand($this->support()));

        $tester->execute(['--role' => 'ROLE_ADMIN', '--disabled' => null, '--limit' => '10', '--offset' => '5', '--json' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertEquals([new ListUsersQuery('ROLE_ADMIN', true, 10, 5)], $this->dispatched);
        self::assertSame(AdminUserResource::collection([$this->user]), json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testListWithoutOptionsListsEveryUserOnTheFirstPage(): void
    {
        $tester = new CommandTester(new UserListCommand($this->support()));

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertEquals([new ListUsersQuery(null, null, ListUsersQuery::DEFAULT_LIMIT, 0)], $this->dispatched);
        self::assertStringContainsString('admin@baander.app', $tester->getDisplay());
        self::assertStringContainsString('Users 1-1 of 1.', $tester->getDisplay());
    }

    public function testListDisabledFalseListsEnabledUsers(): void
    {
        $tester = new CommandTester(new UserListCommand($this->support()));

        $tester->execute(['--disabled' => 'false']);

        self::assertEquals([new ListUsersQuery(null, false, ListUsersQuery::DEFAULT_LIMIT, 0)], $this->dispatched);
    }

    public function testListRejectsUnreadableOptionsAsInvalid(): void
    {
        foreach ([['--limit' => 'ten'], ['--offset' => '1.5'], ['--disabled' => 'maybe']] as $options) {
            $tester = new CommandTester(new UserListCommand($this->support()));

            self::assertSame(Command::INVALID, $tester->execute($options), (string) json_encode($options));
        }
        self::assertSame([], $this->dispatched);
    }

    public function testListReportsTheHandlersRejectionAsInvalid(): void
    {
        $this->failure = new InvalidInputException('limit must be between 1 and 100.');
        $tester = new CommandTester(new UserListCommand($this->support()));

        self::assertSame(Command::INVALID, $tester->execute(['--limit' => '500']));
        self::assertStringContainsString('limit must be between 1 and 100.', $tester->getDisplay());
    }

    public function testRenameDispatchesTheRename(): void
    {
        $tester = new CommandTester(new UserRenameCommand($this->support()));

        self::assertSame(Command::SUCCESS, $tester->execute(['identifier' => 'admin@baander.app', 'name' => 'Admin']));
        self::assertEquals([new RenameUserCommand('admin@baander.app', 'Admin')], $this->dispatched);
    }

    public function testUserWritesPrintTheUserAsTheApiReturnsItWithJson(): void
    {
        $expected = AdminUserResource::from($this->user);

        foreach ([
            [new UserRenameCommand($this->support()), ['identifier' => 'admin@baander.app', 'name' => 'Admin']],
            [new UserRolesCommand($this->support()), ['identifier' => 'admin@baander.app', 'roles' => ['ROLE_USER', 'ROLE_ADMIN']]],
            [new DisableUserCommand($this->support()), ['identifier' => 'admin@baander.app']],
            [new EnableUserCommand($this->support()), ['identifier' => 'admin@baander.app']],
        ] as [$command, $arguments]) {
            $tester = new CommandTester($command);

            self::assertSame(Command::SUCCESS, $tester->execute($arguments + ['--json' => true]), (string) $command->getName());
            self::assertSame($expected, json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR), (string) $command->getName());
        }
    }

    public function testDisableAndEnableDispatchTheUseCasesAndAnUnknownUserFails(): void
    {
        self::assertSame(Command::SUCCESS, (new CommandTester(new DisableUserCommand($this->support())))->execute(['identifier' => 'admin@baander.app']));
        self::assertSame(Command::SUCCESS, (new CommandTester(new EnableUserCommand($this->support())))->execute(['identifier' => 'admin@baander.app']));
        self::assertEquals([new DisableUserMessage('admin@baander.app'), new EnableUserMessage('admin@baander.app')], $this->dispatched);

        $this->failure = new HandlerFailedException(new Envelope(new \stdClass()), [UserNotFoundException::forIdentifier('nobody@baander.app')]);
        foreach ([new DisableUserCommand($this->support()), new EnableUserCommand($this->support())] as $command) {
            $tester = new CommandTester($command);
            self::assertSame(Command::FAILURE, $tester->execute(['identifier' => 'nobody@baander.app']), (string) $command->getName());
            self::assertStringContainsString('User "nobody@baander.app" not found.', $tester->getDisplay());
        }
    }

    public function testRenameReportsAnInvalidNameAsInvalidAndAnUnknownUserAsFailure(): void
    {
        $this->failure = new HandlerFailedException(new Envelope(new \stdClass()), [new InvalidInputException('Name cannot be empty.')]);
        $tester = new CommandTester(new UserRenameCommand($this->support()));
        self::assertSame(Command::INVALID, $tester->execute(['identifier' => 'admin@baander.app', 'name' => ' ']));
        self::assertStringContainsString('Name cannot be empty.', $tester->getDisplay());

        $this->failure = UserNotFoundException::forIdentifier('nobody@baander.app');
        $tester = new CommandTester(new UserRenameCommand($this->support()));
        self::assertSame(Command::FAILURE, $tester->execute(['identifier' => 'nobody@baander.app', 'name' => 'Nobody']));
        self::assertStringContainsString('User "nobody@baander.app" not found.', $tester->getDisplay());
    }

    public function testDeleteWithoutATerminalOrForceDeletesNothing(): void
    {
        $tester = new CommandTester(new UserDeleteCommand($this->support()));

        self::assertSame(Command::INVALID, $tester->execute(['identifier' => 'admin@baander.app'], ['interactive' => false]));
        self::assertSame([], $this->dispatched);
        self::assertStringContainsString('--force', $tester->getDisplay());
    }

    public function testDeleteAsksOnATerminal(): void
    {
        $declined = new CommandTester(new UserDeleteCommand($this->support()));
        $declined->setInputs(['no']);
        self::assertSame(Command::FAILURE, $declined->execute(['identifier' => 'admin@baander.app']));
        self::assertSame([], $this->dispatched);

        $confirmed = new CommandTester(new UserDeleteCommand($this->support()));
        $confirmed->setInputs(['yes']);
        self::assertSame(Command::SUCCESS, $confirmed->execute(['identifier' => 'admin@baander.app']));
        self::assertEquals([new DeleteUserCommand('admin@baander.app')], $this->dispatched);
    }

    public function testDeleteWithForceDeletes(): void
    {
        $tester = new CommandTester(new UserDeleteCommand($this->support()));

        self::assertSame(Command::SUCCESS, $tester->execute(['identifier' => 'admin@baander.app', '--force' => true], ['interactive' => false]));
        self::assertEquals([new DeleteUserCommand('admin@baander.app')], $this->dispatched);
    }

    public function testDeleteWithJsonPrintsNothingAsTheApiAnswersWithNoContent(): void
    {
        $tester = new CommandTester(new UserDeleteCommand($this->support()));

        self::assertSame(Command::SUCCESS, $tester->execute(['identifier' => 'admin@baander.app', '--force' => true, '--json' => true], ['interactive' => false]));
        self::assertSame('', $tester->getDisplay());
        self::assertEquals([new DeleteUserCommand('admin@baander.app')], $this->dispatched);
    }

    public function testRolesDispatchesTheCompleteRoleSet(): void
    {
        $tester = new CommandTester(new UserRolesCommand($this->support()));

        self::assertSame(Command::SUCCESS, $tester->execute(['identifier' => 'admin@baander.app', 'roles' => ['ROLE_USER', 'ROLE_ADMIN']]));
        self::assertEquals([new SetUserRolesCommand('admin@baander.app', ['ROLE_USER', 'ROLE_ADMIN'])], $this->dispatched);
        self::assertStringContainsString('ROLE_USER, ROLE_ADMIN', $tester->getDisplay());
    }

    public function testRolesReportsAnUnknownRoleAsInvalid(): void
    {
        $this->failure = new InvalidInputException('Unknown role "ROLE_OWNER".');
        $tester = new CommandTester(new UserRolesCommand($this->support()));

        self::assertSame(Command::INVALID, $tester->execute(['identifier' => 'admin@baander.app', 'roles' => ['ROLE_OWNER']]));
    }

    private function support(): AdminCommandSupport
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            if ($this->failure !== null) {
                throw $this->failure;
            }
            $this->dispatched[] = $message;
            $result = $message instanceof ListUsersQuery ? new UserPage([$this->user], 1) : $this->user;

            return new Envelope($message, [new HandledStamp($result, 'handler')]);
        });

        return new AdminCommandSupport($bus);
    }
}
