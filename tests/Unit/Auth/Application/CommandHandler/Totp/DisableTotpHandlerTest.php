<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\Totp\DisableTotpCommand;
use App\Auth\Application\CommandHandler\Totp\DisableTotpHandler;
use App\Auth\Application\Exception\TotpChangeRefusal;
use App\Auth\Application\Exception\TotpChangeRefusedException;
use App\Auth\Application\Exception\TotpSecretUnavailableException;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Port\TotpVerifierInterface;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class DisableTotpHandlerTest extends TestCase
{
    private UserRepositoryInterface&MockObject $userRepository;
    private TotpVerifierInterface&Stub $totpVerifier;
    private DisableTotpHandler $handler;
    private User $user;

    protected function setUp(): void
    {
        $this->user = User::register(new Email('test@baander.app'), 'hashed', 'Alice');
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->userRepository->method('findByUuid')->willReturn($this->user);
        $this->totpVerifier = $this->createStub(TotpVerifierInterface::class);
        $this->handler = new DisableTotpHandler($this->userRepository, $this->totpVerifier);
    }

    public function testAnUnspentCodeTurnsTotpOff(): void
    {
        $this->user->enableTotp('sealed-secret');
        $this->totpVerifier->method('verifyAndSpend')->willReturn(true);
        $this->userRepository->expects($this->once())->method('save')->with($this->user);

        ($this->handler)(new DisableTotpCommand($this->user->getId()->toString(), '123456'));

        $this->assertFalse($this->user->hasTotp());
    }

    public function testAnUnknownUserIsNotFound(): void
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByUuid')->willReturn(null);
        $this->userRepository->expects($this->never())->method('save');

        $this->expectException(UserNotFoundException::class);

        (new DisableTotpHandler($users, $this->totpVerifier))(new DisableTotpCommand(Uuid::v4()->toString(), '123456'));
    }

    public function testWithoutTotpTheRequestIsRefused(): void
    {
        $this->assertRefused(TotpChangeRefusal::NotEnabled);
    }

    public function testAWrongOrSpentCodeIsRefusedAndTotpStaysOn(): void
    {
        $this->user->enableTotp('sealed-secret');
        $this->totpVerifier->method('verifyAndSpend')->willReturn(false);

        $this->assertRefused(TotpChangeRefusal::InvalidCode);
        $this->assertTrue($this->user->hasTotp());
    }

    public function testASecretThatCannotBeOpenedIsRefusedAndTotpStaysOn(): void
    {
        $this->user->enableTotp('sealed-under-another-key');
        $this->totpVerifier->method('verifyAndSpend')->willThrowException(TotpSecretUnavailableException::forStoredSecret());

        $this->assertRefused(TotpChangeRefusal::SecretUnavailable);
        $this->assertTrue($this->user->hasTotp());
    }

    private function assertRefused(TotpChangeRefusal $refusal): void
    {
        $this->userRepository->expects($this->never())->method('save');
        try {
            ($this->handler)(new DisableTotpCommand($this->user->getId()->toString(), '123456'));
            self::fail('The request must be refused.');
        } catch (TotpChangeRefusedException $exception) {
            $this->assertSame($refusal, $exception->refusal);
        }
    }
}
