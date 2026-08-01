<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\User\RegisterUserCommand;
use App\Auth\Application\CommandHandler\User\RegisterUserHandler;
use App\Auth\Application\Port\EmailVerificationTokenRepositoryInterface;
use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\EmailVerificationTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
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
    private EmailVerificationTokenRepositoryInterface&MockObject $emailVerificationTokenRepository;
    private RegisterUserHandler $handler;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->passwordHasher = $this->createMock(PasswordHasherInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->bus->method('dispatch')->willReturnCallback(fn (object $m) => new Envelope($m));
        $this->emailVerificationTokenRepository = $this->createMock(EmailVerificationTokenRepositoryInterface::class);
        $this->emailVerificationTokenRepository->method('createForUser')->willReturnCallback(
            fn ($userId, $token, $expiresAt) => new EmailVerificationTokenEntity(
                new UserEntity(new PublicId(), 'Test', 'test@example.com', 'pw', ''),
                $token,
                $expiresAt,
            ),
        );
        $this->handler = new RegisterUserHandler(
            $this->userRepository,
            $this->passwordHasher,
            $this->eventDispatcher,
            $this->bus,
            $this->emailVerificationTokenRepository,
        );
    }

    public function testRegistersUser(): void
    {
        $email = new Email('test@example.com');
        $this->userRepository->method('existsWithEmail')->willReturn(false);
        $this->passwordHasher->method('hash')->willReturn('hashed-pw');
        $this->userRepository->expects($this->once())->method('save');

        $user = ($this->handler)(new RegisterUserCommand($email, 'Alice', 'password123'));

        $this->assertSame('Alice', $user->getName());
        $this->assertSame('test@example.com', $user->getEmail());
    }

    public function testThrowsOnDuplicateEmail(): void
    {
        $email = new Email('test@example.com');
        $this->userRepository->method('existsWithEmail')->willReturn(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        ($this->handler)(new RegisterUserCommand($email, 'Alice', 'password123'));
    }

    public function testCreatesEmailVerificationTokenAfterRegistration(): void
    {
        $email = new Email('test@example.com');
        $this->userRepository->method('existsWithEmail')->willReturn(false);
        $this->passwordHasher->method('hash')->willReturn('hashed-pw');
        $this->userRepository->expects($this->once())->method('save');
        $this->emailVerificationTokenRepository
            ->expects($this->once())
            ->method('createForUser')
            ->with(
                $this->isInstanceOf(Uuid::class),
                $this->matchesRegularExpression('/^[0-9A-Z]{26}$/'),
                $this->isInstanceOf(\DateTimeImmutable::class),
            );

        ($this->handler)(new RegisterUserCommand($email, 'Alice', 'password123'));
    }
}
