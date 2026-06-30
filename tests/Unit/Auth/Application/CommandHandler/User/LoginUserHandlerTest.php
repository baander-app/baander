<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\User\LoginUserCommand;
use App\Auth\Application\CommandHandler\User\LoginUserHandler;
use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Email;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LoginUserHandlerTest extends TestCase
{
    private UserRepositoryInterface&MockObject $userRepository;
    private PasswordHasherInterface&MockObject $passwordHasher;
    private LoginUserHandler $handler;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->passwordHasher = $this->createMock(PasswordHasherInterface::class);
        $this->handler = new LoginUserHandler($this->userRepository, $this->passwordHasher);
    }

    public function testSuccessfulLogin(): void
    {
        $user = User::register(new Email('test@example.com'), 'hashed', 'Alice');
        $this->userRepository->method('findByEmail')->willReturn($user);
        $this->passwordHasher->method('verify')->with('pw', 'hashed')->willReturn(true);

        $result = ($this->handler)(new LoginUserCommand(new Email('test@example.com'), 'pw'));

        $this->assertSame('Alice', $result->getName());
    }

    public function testThrowsOnUserNotFound(): void
    {
        $this->userRepository->method('findByEmail')->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid credentials');

        ($this->handler)(new LoginUserCommand(new Email('a@b.com'), 'pw'));
    }

    public function testThrowsOnWrongPassword(): void
    {
        $user = User::register(new Email('test@example.com'), 'hashed', 'Alice');
        $this->userRepository->method('findByEmail')->willReturn($user);
        $this->passwordHasher->method('verify')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid credentials');

        ($this->handler)(new LoginUserCommand(new Email('test@example.com'), 'wrong'));
    }
}
