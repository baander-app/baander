<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\User\RegisterUserCommand;
use App\Auth\Application\CommandHandler\User\RegisterUserHandler;
use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Email;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use RuntimeException;

final class RegisterUserHandlerTest extends TestCase
{
    private UserRepositoryInterface&MockObject $userRepository;
    private PasswordHasherInterface&MockObject $passwordHasher;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private MessageBusInterface&MockObject $bus;
    private RegisterUserHandler $handler;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->passwordHasher = $this->createMock(PasswordHasherInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->bus->method('dispatch')->willReturnCallback(fn (object $m) => new Envelope($m));
        $this->handler = new RegisterUserHandler($this->userRepository, $this->passwordHasher, $this->eventDispatcher, $this->bus);
    }

    public function testRegistersUser(): void
    {
        $email = new Email('test@example.com');
        $this->userRepository->method('existsWithEmail')->with($email)->willReturn(false);
        $this->passwordHasher->method('hash')->with('password123')->willReturn('hashed-pw');
        $this->userRepository->expects($this->once())->method('save');

        $user = ($this->handler)(new RegisterUserCommand($email, 'Alice', 'password123'));

        $this->assertSame('Alice', $user->getName());
        $this->assertSame('test@example.com', $user->getEmail());
    }

    public function testThrowsOnDuplicateEmail(): void
    {
        $email = new Email('test@example.com');
        $this->userRepository->method('existsWithEmail')->with($email)->willReturn(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        ($this->handler)(new RegisterUserCommand($email, 'Alice', 'password123'));
    }
}
