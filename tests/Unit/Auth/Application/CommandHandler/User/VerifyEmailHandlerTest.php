<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\VerifyEmailCommand;
use App\Auth\Application\CommandHandler\User\VerifyEmailHandler;
use App\Auth\Application\Port\EmailVerificationTokenRepositoryInterface;
use App\Auth\Domain\Exception\EmailVerificationException;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\EmailVerificationTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class VerifyEmailHandlerTest extends TestCase
{
    private EmailVerificationTokenRepositoryInterface $tokenRepository;
    private UserRepositoryInterface $userRepository;
    private EventDispatcherInterface $eventDispatcher;
    private VerifyEmailHandler $handler;

    protected function setUp(): void
    {
        $this->tokenRepository = $this->createStub(EmailVerificationTokenRepositoryInterface::class);
        $this->userRepository = $this->createStub(UserRepositoryInterface::class);
        $this->eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $this->handler = $this->createVerifyEmailHandlerFixture();
    }

    private function createVerifyEmailHandlerFixture(): VerifyEmailHandler
    {
        $fixture = new VerifyEmailHandler(
            $this->tokenRepository,
            $this->userRepository,
            $this->eventDispatcher,
        );
        return $fixture;
    }

    public function testVerifyEmailMarksUserVerifiedAndDeletesToken(): void
    {
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->tokenRepository = $this->createMock(EmailVerificationTokenRepositoryInterface::class);
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->handler = $this->createVerifyEmailHandlerFixture();

        $tokenString = 'valid-token';
        $userEntity = new UserEntity(new PublicId(), 'Alice', 'alice@example.com', 'hashed-pw', '');
        $tokenEntity = new EmailVerificationTokenEntity(
            $userEntity,
            $tokenString,
            new \DateTimeImmutable('+1 hour'),
        );

        $this->tokenRepository->method('findByToken')->willReturn($tokenEntity);
        $this->userRepository->method('findByUuid')->willReturn(
            \App\Auth\Domain\Model\User::register(new Email('alice@example.com'), 'hashed-pw', 'Alice'),
        );
        $this->userRepository->expects($this->once())->method('save');
        $this->tokenRepository->expects($this->once())->method('delete')->with($tokenEntity);
        $this->eventDispatcher->expects($this->once())->method('dispatch')->with(
            $this->isInstanceOf(\App\Auth\Domain\Event\EmailVerified::class),
        );

        $result = ($this->handler)(new VerifyEmailCommand($tokenString));

        $this->assertTrue($result);
    }

    public function testEmptyTokenThrows(): void
    {
        $this->expectException(EmailVerificationException::class);

        ($this->handler)(new VerifyEmailCommand(''));
    }

    public function testUnknownTokenThrows(): void
    {
        $this->tokenRepository->method('findByToken')->willReturn(null);

        $this->expectException(EmailVerificationException::class);

        ($this->handler)(new VerifyEmailCommand('unknown'));
    }

    public function testExpiredTokenThrows(): void
    {
        $userEntity = new UserEntity(new PublicId(), 'Alice', 'alice@example.com', 'hashed-pw', '');
        $tokenEntity = new EmailVerificationTokenEntity(
            $userEntity,
            'expired-token',
            new \DateTimeImmutable('-1 hour'),
        );

        $this->tokenRepository->method('findByToken')->willReturn($tokenEntity);

        $this->expectException(EmailVerificationException::class);

        ($this->handler)(new VerifyEmailCommand('expired-token'));
    }

    public function testUsedTokenThrows(): void
    {
        $userEntity = new UserEntity(new PublicId(), 'Alice', 'alice@example.com', 'hashed-pw', '');
        $tokenEntity = new EmailVerificationTokenEntity(
            $userEntity,
            'used-token',
            new \DateTimeImmutable('+1 hour'),
        );
        $tokenEntity->markUsed();

        $this->tokenRepository->method('findByToken')->willReturn($tokenEntity);

        $this->expectException(EmailVerificationException::class);

        ($this->handler)(new VerifyEmailCommand('used-token'));
    }
}
