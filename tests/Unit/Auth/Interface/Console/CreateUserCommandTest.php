<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Console;

use App\Auth\Domain\Model\User;
use App\Auth\Interface\Console\CreateUserCommand;
use App\Shared\Domain\Model\Email;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use RuntimeException;

final class CreateUserCommandTest extends TestCase
{
    private MessageBusInterface&MockObject $commandBus;
    private CreateUserCommand $command;

    protected function setUp(): void
    {
        $this->commandBus = $this->createMock(MessageBusInterface::class);
        $this->command = new CreateUserCommand($this->commandBus);
    }

    private function mockDispatchReturningUser(
        string $publicId = 'usr_test123',
        string $name = 'Alice',
        string $email = 'alice@example.com',
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
        $this->mockDispatchReturningUser('usr_test123', 'Alice', 'alice@example.com', ['ROLE_USER']);

        $tester = new CommandTester($this->createCommandWithStream("securepassword\n"));
        $tester->execute([
            'email' => 'alice@example.com',
            'name' => 'Alice',
            '--password' => true,
            '--role' => 'user',
        ], ['interactive' => false]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('User created successfully', $tester->getDisplay());
    }

    public function testCreateAdminUser(): void
    {
        $this->mockDispatchReturningUser('usr_test123', 'Admin', 'admin@example.com', ['ROLE_ADMIN']);

        $tester = new CommandTester($this->createCommandWithStream("adminpassword\n"));
        $tester->execute([
            'email' => 'admin@example.com',
            'name' => 'Admin',
            '--password' => true,
            '--role' => 'admin',
        ], ['interactive' => false]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
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
            'email' => 'exists@example.com',
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
            'email' => 'test@example.com',
            'name' => 'Alice',
            '--password' => true,
            '--role' => 'superadmin',
        ], ['interactive' => false]);

        $this->assertSame(Command::FAILURE, $statusCode);
        $this->assertStringContainsString('Invalid role', $tester->getDisplay());
    }

    public function testPasswordTooShort(): void
    {
        $tester = new CommandTester($this->createCommandWithStream("short\n"));
        $tester->execute([
            'email' => 'test@example.com',
            'name' => 'Alice',
            '--password' => true,
        ], ['interactive' => false]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('8 characters', $tester->getDisplay());
    }
}
