<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\User\RequestPasswordResetCommand;
use App\Auth\Application\CommandHandler\User\RequestPasswordResetHandler;
use App\Auth\Application\Port\PasswordResetDeliveryInterface;
use App\Auth\Application\Port\PasswordResetRequestThrottleInterface;
use App\Auth\Application\Port\PasswordResetTokenRepositoryInterface;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class RequestPasswordResetHandlerTest extends TestCase
{
    private UserRepositoryInterface&Stub $userRepository;
    private PasswordResetTokenRepositoryInterface&MockObject $tokenRepository;
    private bool $throttleAccepts = true;
    /** @var list<string> */
    private array $throttledEmails = [];
    /** @var list<array{User, string, \DateTimeImmutable}> */
    private array $delivered = [];
    private RequestPasswordResetHandler $handler;

    protected function setUp(): void
    {
        $this->userRepository = $this->createStub(UserRepositoryInterface::class);
        $this->tokenRepository = $this->createMock(PasswordResetTokenRepositoryInterface::class);
        $this->handler = $this->handler(60);
    }

    public function testChargesTheNormalizedAddressBeforeIssuingAToken(): void
    {
        $this->userRepository->method('findByEmail')->willReturn($this->user());
        $this->tokenRepository->expects($this->once())->method('issue');

        ($this->handler)(new RequestPasswordResetCommand(new Email('Test@Baander.app')));

        self::assertSame(['test@baander.app'], $this->throttledEmails);
    }

    public function testChargesUnknownAddressesToo(): void
    {
        $this->userRepository->method('findByEmail')->willReturn(null);
        $this->tokenRepository->expects($this->never())->method('issue');

        ($this->handler)(new RequestPasswordResetCommand(new Email('unknown@baander.app')));

        self::assertSame(['unknown@baander.app'], $this->throttledEmails);
        self::assertSame([], $this->delivered);
    }

    public function testIssuesNoTokenWhenTheAccountLimitIsReached(): void
    {
        $this->throttleAccepts = false;
        $this->userRepository->method('findByEmail')->willReturn($this->user());
        $this->tokenRepository->expects($this->never())->method('issue');

        ($this->handler)(new RequestPasswordResetCommand(new Email('test@baander.app')));

        self::assertSame([], $this->delivered);
    }

    public function testIssuesARandomTokenToTheUserForTheConfiguredLifetime(): void
    {
        $user = $this->user();
        $this->userRepository->method('findByEmail')->willReturn($user);
        $issued = [];
        $this->tokenRepository->expects($this->exactly(2))->method('issue')
            ->willReturnCallback(static function (Uuid $userId, string $token, \DateTimeImmutable $expiresAt) use (&$issued): void {
                $issued[] = [$userId, $token, $expiresAt];
            });

        $before = new \DateTimeImmutable('+90 minutes');
        $handler = $this->handler(90);
        $handler(new RequestPasswordResetCommand(new Email('test@baander.app')));
        $handler(new RequestPasswordResetCommand(new Email('test@baander.app')));
        $after = new \DateTimeImmutable('+90 minutes');

        [[$userId, $token, $expiresAt], [, $secondToken]] = $issued;
        self::assertTrue($user->getId()->equals($userId), 'The token belongs to the account, not the address.');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token, 'A token carries 256 random bits.');
        self::assertNotSame($token, $secondToken);
        self::assertGreaterThanOrEqual($before->getTimestamp(), $expiresAt->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $expiresAt->getTimestamp());

        self::assertCount(2, $this->delivered, 'Every issued token is delivered.');
        [[$deliveredTo, $deliveredToken, $deliveredExpiry], [, $secondDelivered]] = $this->delivered;
        self::assertSame($user, $deliveredTo);
        self::assertSame($token, $deliveredToken, 'The delivered token is the one whose hash was stored.');
        self::assertSame($expiresAt, $deliveredExpiry);
        self::assertSame($secondToken, $secondDelivered);
    }

    public function testRejectsALifetimeShorterThanAMinute(): void
    {
        $this->tokenRepository->expects($this->never())->method('issue');
        $this->expectException(\InvalidArgumentException::class);

        $this->handler(0);
    }

    private function handler(int $lifetimeMinutes): RequestPasswordResetHandler
    {
        $throttle = $this->createStub(PasswordResetRequestThrottleInterface::class);
        $throttle->method('tryAcquire')->willReturnCallback(function (Email $email): bool {
            $this->throttledEmails[] = $email->toString();

            return $this->throttleAccepts;
        });

        $delivery = $this->createStub(PasswordResetDeliveryInterface::class);
        $delivery->method('deliver')->willReturnCallback(function (User $user, string $token, \DateTimeImmutable $expiresAt): void {
            $this->delivered[] = [$user, $token, $expiresAt];
        });

        return new RequestPasswordResetHandler($this->userRepository, $this->tokenRepository, $throttle, $delivery, $lifetimeMinutes);
    }

    private function user(): User
    {
        return User::register(new Email('test@baander.app'), 'hashed', 'Alice');
    }
}
