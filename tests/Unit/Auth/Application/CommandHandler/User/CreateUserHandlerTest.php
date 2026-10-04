<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\User\CreateUserCommand;
use App\Auth\Application\CommandHandler\User\CreateUserHandler;
use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Domain\Event\UserCreatedByOperator;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Notification\Application\DTO\SeedDefaultPreferencesCommand;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Email;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class CreateUserHandlerTest extends TestCase
{
    private UserRepositoryInterface&Stub $userRepository;
    private PasswordHasherInterface&Stub $passwordHasher;
    private EventDispatcherInterface&Stub $eventDispatcher;
    private MessageBusInterface&Stub $bus;
    private TransactionPortInterface&Stub $transaction;
    private CreateUserHandler $handler;

    protected function setUp(): void
    {
        $this->userRepository = $this->createStub(UserRepositoryInterface::class);
        $this->passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $this->eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $this->bus = $this->createMessageBus();
        $this->transaction = $this->createStub(TransactionPortInterface::class);
        $this->transaction->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $this->handler = $this->createCreateUserHandlerFixture();
    }

    /** @return ($expectCalls is true ? MessageBusInterface&\PHPUnit\Framework\MockObject\MockObject : MessageBusInterface&Stub) */
    private function createMessageBus(bool $expectCalls = false): MessageBusInterface&Stub
    {
        $double = $expectCalls ? $this->createMock(MessageBusInterface::class) : $this->createStub(MessageBusInterface::class);
        $double->method('dispatch')->willReturnCallback(fn (object $m) => new Envelope($m));
        return $double;
    }

    private function createCreateUserHandlerFixture(): CreateUserHandler
    {
        $fixture = new CreateUserHandler($this->userRepository, $this->passwordHasher, $this->eventDispatcher, $this->bus, $this->transaction);
        return $fixture;
    }

    public function testCreatesUserWithCorrectRoleAndDispatchesEvents(): void
    {
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->passwordHasher = $this->createMock(PasswordHasherInterface::class);
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->bus = $this->createMessageBus(expectCalls: true);
        $this->handler = $this->createCreateUserHandlerFixture();

        $email = new Email('test@baander.app');
        $roles = ['ROLE_USER'];

        $this->userRepository->expects($this->once())->method('existsWithEmail')->with($email)->willReturn(false);
        $this->passwordHasher->expects($this->once())->method('hash')->with('password123')->willReturn('hashed-pw');
        $this->userRepository->expects($this->once())->method('save');

        $this->bus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(fn (object $m) => new Envelope($m));

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(fn (object $e) => $e instanceof UserCreatedByOperator));

        $user = ($this->handler)(new CreateUserCommand($email, 'Alice', 'password123', $roles));

        $this->assertSame('Alice', $user->getName());
        $this->assertSame('test@baander.app', $user->getEmail());
        $this->assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testThrowsOnDuplicateEmail(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->handler = $this->createCreateUserHandlerFixture();

        $email = new Email('test@baander.app');
        $this->userRepository->expects($this->once())->method('existsWithEmail')->with($email)->willReturn(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        ($this->handler)(new CreateUserCommand($email, 'Alice', 'password123', ['ROLE_USER']));
    }

    public function testThrowsOnInvalidRole(): void
    {
        $email = new Email('test@baander.app');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid role "ROLE_SUPERUSER"');

        ($this->handler)(new CreateUserCommand($email, 'Alice', 'password123', ['ROLE_SUPERUSER']));
    }

    public function testCreatesAdminUser(): void
    {
        $this->passwordHasher = $this->createMock(PasswordHasherInterface::class);
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->handler = $this->createCreateUserHandlerFixture();

        $email = new Email('admin@baander.app');
        $roles = ['ROLE_ADMIN'];

        $this->userRepository->expects($this->once())->method('existsWithEmail')->with($email)->willReturn(false);
        $this->passwordHasher->expects($this->once())->method('hash')->with('admin-pw')->willReturn('hashed-admin-pw');

        $user = ($this->handler)(new CreateUserCommand($email, 'Admin', 'admin-pw', $roles));

        $this->assertSame('Admin', $user->getName());
        $this->assertSame(['ROLE_ADMIN'], $user->getRoles());
        $this->assertTrue($user->hasRole('ROLE_ADMIN'));
        $this->assertTrue($user->hasRole('ROLE_USER'));
    }

    public function testCreatesRegularUser(): void
    {
        $this->passwordHasher = $this->createMock(PasswordHasherInterface::class);
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->handler = $this->createCreateUserHandlerFixture();

        $email = new Email('user@baander.app');
        $roles = ['ROLE_USER'];

        $this->userRepository->expects($this->once())->method('existsWithEmail')->with($email)->willReturn(false);
        $this->passwordHasher->expects($this->once())->method('hash')->with('user-pw')->willReturn('hashed-user-pw');

        $user = ($this->handler)(new CreateUserCommand($email, 'Bob', 'user-pw', $roles));

        $this->assertSame('Bob', $user->getName());
        $this->assertSame(['ROLE_USER'], $user->getRoles());
        $this->assertTrue($user->hasRole('ROLE_USER'));
        $this->assertFalse($user->hasRole('ROLE_ADMIN'));
    }

    public function testDispatchesSeedDefaultPreferencesCommand(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->bus = $this->createMessageBus(expectCalls: true);
        $this->handler = $this->createCreateUserHandlerFixture();

        $email = new Email('test@baander.app');
        $this->userRepository->expects($this->once())->method('existsWithEmail')->with($email)->willReturn(false);
        $this->passwordHasher->method('hash')->willReturn('hashed-pw');

        $expectedUserId = null;
        $this->bus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (SeedDefaultPreferencesCommand $cmd) use (&$expectedUserId): Envelope {
                $expectedUserId = $cmd->userId;
                return new Envelope($cmd);
            });

        ($this->handler)(new CreateUserCommand($email, 'Alice', 'password123', ['ROLE_USER']));

        $this->assertNotNull($expectedUserId);
    }

    public function testDispatchesUserCreatedByOperatorEvent(): void
    {
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->handler = $this->createCreateUserHandlerFixture();

        $email = new Email('test@baander.app');
        $this->userRepository->expects($this->once())->method('existsWithEmail')->with($email)->willReturn(false);
        $this->passwordHasher->method('hash')->willReturn('hashed-pw');

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (UserCreatedByOperator $event): bool {
                $this->assertSame('test@baander.app', $event->getEmail()->toString());
                $this->assertSame('Alice', $event->getName());
                $this->assertSame(['ROLE_USER'], $event->getRoles());
                $this->assertSame('cli', $event->getSource());
                return true;
            }));

        ($this->handler)(new CreateUserCommand($email, 'Alice', 'password123', ['ROLE_USER']));
    }

    public function testEventFailureEscapesTheTransaction(): void
    {
        $active = false;
        $failure = new \RuntimeException('Outbox insertion failed.');
        $this->transaction = $this->createMock(TransactionPortInterface::class);
        $this->transaction->expects($this->once())->method('run')->willReturnCallback(
            function (callable $operation) use (&$active, $failure): mixed {
                $active = true;
                try {
                    return $operation();
                } catch (\RuntimeException $exception) {
                    $this->assertSame($failure, $exception);
                    throw $exception;
                } finally {
                    $active = false;
                }
            },
        );
        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->userRepository = $userRepository;
        $userRepository->expects($this->once())->method('save')->willReturnCallback(
            function () use (&$active): void {
                $this->assertTrue($active);
            },
        );
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->expects($this->once())->method('dispatch')->willReturnCallback(
            function () use (&$active, $failure): never {
                $this->assertTrue($active);
                throw $failure;
            },
        );
        $this->passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $this->passwordHasher->method('hash')->willReturn('hashed-pw');
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->bus->expects($this->once())->method('dispatch')->willReturnCallback(
            function (object $message) use (&$active): Envelope {
                $this->assertTrue($active);
                return new Envelope($message);
            },
        );
        $this->handler = $this->createCreateUserHandlerFixture();

        $this->expectExceptionObject($failure);
        ($this->handler)(new CreateUserCommand(new Email('alice@baander.app'), 'Alice', 'password123', ['ROLE_USER']));
    }
}
