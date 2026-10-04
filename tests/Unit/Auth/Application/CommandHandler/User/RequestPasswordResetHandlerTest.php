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
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class RequestPasswordResetHandlerTest extends TestCase
{
    private UserRepositoryInterface&Stub $userRepository;
    private PasswordResetTokenRepositoryInterface&MockObject $tokenRepository;
    private RequestPasswordResetHandler $handler;

    protected function setUp(): void
    {
        $this->userRepository = $this->createStub(UserRepositoryInterface::class);
        $this->tokenRepository = $this->createMock(PasswordResetTokenRepositoryInterface::class);
        $this->handler = new RequestPasswordResetHandler($this->userRepository, $this->tokenRepository);
    }

    public function testCreatesTokenForExistingUser(): void
    {
        $user = User::register(new Email('test@baander.app'), 'hashed', 'Alice');
        $this->userRepository->method('findByEmail')->willReturn($user);

        $this->tokenRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->equalTo('test@baander.app'), $this->callback(fn($v) => is_string($v)));

        ($this->handler)(new RequestPasswordResetCommand(new Email('test@baander.app')));
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
        $user = User::register(new Email('test@baander.app'), 'hashed', 'Alice');
        $this->userRepository->method('findByEmail')->willReturn($user);

        $stored = ['test@baander.app' => 'existing-token-string'];
        $this->tokenRepository->expects($this->once())->method('save')
            ->with('test@baander.app', $this->callback(static fn (string $token): bool => \Symfony\Component\Uid\Ulid::isValid($token)))
            ->willReturnCallback(static function (string $email, string $token) use (&$stored): void {
                $stored[$email] = $token;
            });

        ($this->handler)(new RequestPasswordResetCommand(new Email('test@baander.app')));
        self::assertNotSame('existing-token-string', $stored['test@baander.app']);
    }

    public function testCreatesNewTokenWhenNoneExists(): void
    {
        $user = User::register(new Email('test@baander.app'), 'hashed', 'Alice');
        $this->userRepository->method('findByEmail')->willReturn($user);

        $stored = [];
        $this->tokenRepository->expects($this->once())->method('save')
            ->with('test@baander.app', $this->callback(static fn (string $token): bool => \Symfony\Component\Uid\Ulid::isValid($token)))
            ->willReturnCallback(static function (string $email, string $token) use (&$stored): void {
                $stored[$email] = $token;
            });

        ($this->handler)(new RequestPasswordResetCommand(new Email('test@baander.app')));
        self::assertArrayHasKey('test@baander.app', $stored);

    }
}
