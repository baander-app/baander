<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Console;

use App\Auth\Application\Command\User\CreateUserCommand as CreateUserMessage;
use App\Auth\Domain\Model\User;
use App\Auth\Interface\Console\CreateUserCommand;
use App\Shared\Domain\Model\Email;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use RuntimeException;

final class CreateUserCommandTest extends TestCase
{
    private MessageBusInterface&Stub $commandBus;
    private CreateUserCommand $command;

    protected function setUp(): void
    {
        $this->commandBus = $this->createStub(MessageBusInterface::class);
        $this->command = new CreateUserCommand($this->commandBus);
    }

    /** @param array<array-key, string> $roles */
    private function mockDispatchReturningUser(
        string $publicId = 'usr_test123',
        string $name = 'Alice',
        string $email = 'alice@baander.app',
        array $roles = ['ROLE_USER'],
    ): void {
        $user = User::createByOperator(
            Email::fromString($email),
            'hashed-pw',
            $name,
            $roles,
        );

        $this->commandBus->method('dispatch')->willReturnCallback(
            fn (object $m) => new Envelope($m, [new HandledStamp($user, 'handler')]),
        );
    }

    /**
     * @return resource
     */
    private function createInputStream(string $content)
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }

    private function createCommandWithStream(string $stdinContent): CreateUserCommand
    {
        return new CreateUserCommand($this->commandBus, $this->createInputStream($stdinContent));
    }

    public function testCreateUserSuccess(): void
    {
        $this->mockDispatchReturningUser('usr_test123', 'Alice', 'alice@baander.app', ['ROLE_USER']);

        $tester = new CommandTester($this->createCommandWithStream("securepassword\n"));
        $tester->execute([
            'email' => 'alice@baander.app',
            'name' => 'Alice',
            '--password' => true,
            '--role' => 'user',
        ], ['interactive' => false]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('User created successfully', $tester->getDisplay());
    }

    public function testCreateAdminUser(): void
    {
        $this->mockDispatchReturningUser('usr_test123', 'Admin', 'admin@baander.app', ['ROLE_ADMIN']);

        $tester = new CommandTester($this->createCommandWithStream("adminpassword\n"));
        $tester->execute([
            'email' => 'admin@baander.app',
            'name' => 'Admin',
            '--password' => true,
            '--role' => 'admin',
        ], ['interactive' => false]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    /** @return iterable<string, array{list<string>, list<string>}> */
    public static function roleOptions(): iterable
    {
        yield 'super-admin' => [['super-admin'], ['ROLE_SUPER_ADMIN']];
        yield 'repeated' => [['user', 'admin', 'super-admin'], ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN']];
        yield 'a role twice' => [['admin', 'admin'], ['ROLE_ADMIN']];
    }

    /**
     * @param list<string> $options
     * @param list<string> $roles
     */
    #[DataProvider('roleOptions')]
    public function testEveryRoleOptionReachesTheUseCase(array $options, array $roles): void
    {
        $dispatched = [];
        $user = User::createByOperator(Email::fromString('root@baander.app'), 'hashed-pw', 'Root', $roles);
        $this->commandBus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched, $user): Envelope {
                $dispatched[] = $message;

                return new Envelope($message, [new HandledStamp($user, 'handler')]);
            },
        );

        $tester = new CommandTester($this->createCommandWithStream("securepassword\n"));
        $tester->execute([
            'email' => 'root@baander.app',
            'name' => 'Root',
            '--password' => true,
            '--role' => $options,
        ], ['interactive' => false]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(CreateUserMessage::class, $dispatched[0]);
        $this->assertSame($roles, $dispatched[0]->getRoles());
    }

    public function testInvalidEmailFormat(): void
    {
        $tester = new CommandTester($this->command);
        $tester->execute([
            'email' => 'not-an-email',
            'name' => 'Alice',
        ], ['interactive' => false]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid email', $tester->getDisplay());
    }

    public function testDuplicateEmail(): void
    {
        $this->commandBus->method('dispatch')->willThrowException(
            new RuntimeException('A user with this email already exists.'),
        );

        $tester = new CommandTester($this->createCommandWithStream("password123\n"));
        $tester->execute([
            'email' => 'exists@baander.app',
            'name' => 'Alice',
            '--password' => true,
        ], ['interactive' => false]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('already exists', $tester->getDisplay());
    }

    public function testInvalidRole(): void
    {
        $tester = new CommandTester($this->createCommandWithStream("password123\n"));
        $statusCode = $tester->execute([
            'email' => 'test@baander.app',
            'name' => 'Alice',
            '--password' => true,
            '--role' => 'superadmin',
        ], ['interactive' => false]);

        $this->assertSame(Command::FAILURE, $statusCode);
        $this->assertStringContainsString('Invalid role', $tester->getDisplay());
    }

    /** @return iterable<string, array{string}> */
    public static function passwordsOutsideThePolicy(): iterable
    {
        yield 'seven characters' => ['1234567'];
        yield 'four two-byte characters, eight bytes' => [str_repeat('æ', 4)];
        yield '256 characters' => [str_repeat('a', 256)];
    }

    #[DataProvider('passwordsOutsideThePolicy')]
    public function testRejectsAPasswordOutsideThePolicyWithoutCreatingTheUser(string $password): void
    {
        $commandBus = $this->createMock(MessageBusInterface::class);
        $commandBus->expects($this->never())->method('dispatch');

        $tester = new CommandTester(new CreateUserCommand($commandBus, $this->createInputStream($password . "\n")));
        $tester->execute([
            'email' => 'test@baander.app',
            'name' => 'Alice',
            '--password' => true,
        ], ['interactive' => false]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('between 8 and 255 characters', $tester->getDisplay());
    }

    /** @return iterable<string, array{string}> */
    public static function passwordsAtThePolicyBounds(): iterable
    {
        yield 'eight two-byte characters' => [str_repeat('æ', 8)];
        yield '255 two-byte characters' => [str_repeat('æ', 255)];
    }

    #[DataProvider('passwordsAtThePolicyBounds')]
    public function testAcceptsThePolicyBoundsCountedInCharacters(string $password): void
    {
        $dispatched = [];
        $user = User::createByOperator(Email::fromString('alice@baander.app'), 'hashed-pw', 'Alice', ['ROLE_USER']);
        $this->commandBus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched, $user): Envelope {
                $dispatched[] = $message;

                return new Envelope($message, [new HandledStamp($user, 'handler')]);
            },
        );

        $tester = new CommandTester($this->createCommandWithStream($password . "\n"));
        $tester->execute([
            'email' => 'alice@baander.app',
            'name' => 'Alice',
            '--password' => true,
        ], ['interactive' => false]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(CreateUserMessage::class, $dispatched[0]);
        $this->assertSame($password, $dispatched[0]->getPlainPassword());
    }
}
