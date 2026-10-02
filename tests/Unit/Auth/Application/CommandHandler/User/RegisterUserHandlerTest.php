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
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use RuntimeException;

final class RegisterUserHandlerTest extends TestCase
{
    private UserRepositoryInterface $userRepository;
    private PasswordHasherInterface $passwordHasher;
    private EventDispatcherInterface $eventDispatcher;
    private MessageBusInterface $bus;
    private EmailVerificationTokenRepositoryInterface $emailVerificationTokenRepository;
    private TransactionPortInterface $transaction;
    private RegisterUserHandler $handler;

    protected function setUp(): void
    {
        $this->userRepository = $this->createStub(UserRepositoryInterface::class);
        $this->passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $this->eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $this->bus = $this->createStub(MessageBusInterface::class);
        $this->bus->method('dispatch')->willReturnCallback(fn (object $m) => new Envelope($m));
        $this->emailVerificationTokenRepository = $this->createVerificationTokenRepository();
        $this->transaction = $this->createStub(TransactionPortInterface::class);
        $this->transaction->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $this->handler = $this->createRegisterUserHandlerFixture();
    }

    private function createVerificationTokenRepository(bool $expectCalls = false): EmailVerificationTokenRepositoryInterface
    {
        $double = $expectCalls ? $this->createMock(EmailVerificationTokenRepositoryInterface::class) : $this->createStub(EmailVerificationTokenRepositoryInterface::class);
        $double->method('createForUser')->willReturnCallback(
            fn ($userId, $token, $expiresAt) => new EmailVerificationTokenEntity(
                new UserEntity(new PublicId(), 'Test', 'test@baander.app', 'pw', ''),
                $token,
                $expiresAt,
            ),
        );
        return $double;
    }

    private function createRegisterUserHandlerFixture(): RegisterUserHandler
    {
        $fixture = new RegisterUserHandler(
            $this->userRepository,
            $this->passwordHasher,
            $this->eventDispatcher,
            $this->bus,
            $this->emailVerificationTokenRepository,
            $this->transaction,
        );
        return $fixture;
    }

    public function testRegistersUser(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->handler = $this->createRegisterUserHandlerFixture();

        $email = new Email('test@baander.app');
        $this->userRepository->method('existsWithEmail')->willReturn(false);
        $this->passwordHasher->method('hash')->willReturn('hashed-pw');
        $this->userRepository->expects($this->once())->method('save');

        $user = ($this->handler)(new RegisterUserCommand($email, 'Alice', 'password123'));

        $this->assertSame('Alice', $user->getName());
        $this->assertSame('test@baander.app', $user->getEmail());
    }

    public function testThrowsOnDuplicateEmail(): void
    {
        $email = new Email('test@baander.app');
        $this->userRepository->method('existsWithEmail')->willReturn(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        ($this->handler)(new RegisterUserCommand($email, 'Alice', 'password123'));
    }

    public function testCreatesEmailVerificationTokenAfterRegistration(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->emailVerificationTokenRepository = $this->createVerificationTokenRepository(expectCalls: true);
        $this->handler = $this->createRegisterUserHandlerFixture();

        $email = new Email('test@baander.app');
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
        $this->emailVerificationTokenRepository = $this->createMock(EmailVerificationTokenRepositoryInterface::class);
        $this->emailVerificationTokenRepository->expects($this->once())->method('createForUser')->willReturnCallback(
            function ($userId, string $token, \DateTimeImmutable $expiresAt) use (&$active): EmailVerificationTokenEntity {
                $this->assertTrue($active);
                return new EmailVerificationTokenEntity(
                    new UserEntity(new PublicId(), 'Alice', 'alice@baander.app', 'hashed-pw', ''),
                    $token,
                    $expiresAt,
                );
            },
        );
        $this->handler = $this->createRegisterUserHandlerFixture();

        $this->expectExceptionObject($failure);
        ($this->handler)(new RegisterUserCommand(new Email('alice@baander.app'), 'Alice', 'password123'));
    }
}
