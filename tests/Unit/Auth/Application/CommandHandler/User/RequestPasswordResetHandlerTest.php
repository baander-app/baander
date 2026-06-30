<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\User\RequestPasswordResetCommand;
use App\Auth\Application\CommandHandler\User\RequestPasswordResetHandler;
use App\Auth\Application\Port\PasswordResetTokenRepositoryInterface;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Email;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RequestPasswordResetHandlerTest extends TestCase
{
    private UserRepositoryInterface&MockObject $userRepository;
    private PasswordResetTokenRepositoryInterface&MockObject $tokenRepository;
    private RequestPasswordResetHandler $handler;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->tokenRepository = $this->createMock(PasswordResetTokenRepositoryInterface::class);
        $this->handler = new RequestPasswordResetHandler($this->userRepository, $this->tokenRepository);
    }

    public function testCreatesTokenForExistingUser(): void
    {
        $user = User::register(new Email('test@example.com'), 'hashed', 'Alice');
        $this->userRepository->method('findByEmail')->willReturn($user);

        $this->tokenRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->equalTo('test@example.com'), $this->callback(fn($v) => is_string($v)));

        ($this->handler)(new RequestPasswordResetCommand(new Email('test@example.com')));
    }

    public function testDoesNothingForUnknownEmail(): void
    {
        $this->userRepository->method('findByEmail')->willReturn(null);

        $this->tokenRepository
            ->expects($this->never())
            ->method('save');

        ($this->handler)(new RequestPasswordResetCommand(new Email('unknown@example.com')));
    }

    public function testUpdatesTokenWhenOneAlreadyExists(): void
    {
        $user = User::register(new Email('test@example.com'), 'hashed', 'Alice');
        $this->userRepository->method('findByEmail')->willReturn($user);

        // Repository already has an existing token for this email
        $this->tokenRepository
            ->method('findByEmail')
            ->with('test@example.com')
            ->willReturn('existing-token-string');

        $this->tokenRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->equalTo('test@example.com'), $this->callback(fn($v) => is_string($v)));

        ($this->handler)(new RequestPasswordResetCommand(new Email('test@example.com')));
    }

    public function testCreatesNewTokenWhenNoneExists(): void
    {
        $user = User::register(new Email('test@example.com'), 'hashed', 'Alice');
        $this->userRepository->method('findByEmail')->willReturn($user);

        // No existing token for this email
        $this->tokenRepository
            ->method('findByEmail')
            ->with('test@example.com')
            ->willReturn(null);

        $this->tokenRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->equalTo('test@example.com'), $this->callback(fn($v) => is_string($v)));

        ($this->handler)(new RequestPasswordResetCommand(new Email('test@example.com')));
    }
}
