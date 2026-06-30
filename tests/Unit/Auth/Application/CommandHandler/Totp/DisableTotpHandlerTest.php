<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\Totp\DisableTotpCommand;
use App\Auth\Application\CommandHandler\Totp\DisableTotpHandler;
use App\Auth\Application\Port\TotpVerifierInterface;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Email;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DisableTotpHandlerTest extends TestCase
{
    private UserRepositoryInterface&MockObject $userRepository;
    private TotpVerifierInterface&MockObject $totpVerifier;
    private DisableTotpHandler $handler;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->totpVerifier = $this->createMock(TotpVerifierInterface::class);
        $this->handler = new DisableTotpHandler($this->userRepository, $this->totpVerifier);
    }

    public function testDisablesTotpWithValidCode(): void
    {
        $user = User::register(new Email('test@example.com'), 'hashed', 'Alice');
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');

        $this->userRepository->method('findByUuid')->willReturn($user);
        $this->totpVerifier->method('verifyCode')->with('JBSWY3DPEHPK3PXP', '123456')->willReturn(true);
        $this->userRepository->expects($this->once())->method('save');

        ($this->handler)(new DisableTotpCommand($user->getId()->toString(), '123456'));

        $this->assertNull($user->getTotpSecret());
    }

    public function testThrowsOnUserNotFound(): void
    {
        $this->userRepository->method('findByUuid')->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');

        ($this->handler)(new DisableTotpCommand(\App\Shared\Domain\Model\Uuid::v4()->toString(), '123456'));
    }

    public function testThrowsOnInvalidCode(): void
    {
        $user = User::register(new Email('test@example.com'), 'hashed', 'Alice');
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');

        $this->userRepository->method('findByUuid')->willReturn($user);
        $this->totpVerifier->method('verifyCode')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid TOTP code');

        ($this->handler)(new DisableTotpCommand($user->getId()->toString(), '000000'));
    }

    public function testThrowsWhenTotpNotEnabled(): void
    {
        $user = User::register(new Email('test@example.com'), 'hashed', 'Alice');
        // User has no TOTP secret set

        $this->userRepository->method('findByUuid')->willReturn($user);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TOTP is not enabled');

        ($this->handler)(new DisableTotpCommand($user->getId()->toString(), '123456'));
    }

    public function testDoesNotClearSecretOnInvalidCode(): void
    {
        $user = User::register(new Email('test@example.com'), 'hashed', 'Alice');
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');

        $this->userRepository->method('findByUuid')->willReturn($user);
        $this->totpVerifier->method('verifyCode')->willReturn(false);
        $this->userRepository->expects($this->never())->method('save');

        try {
            ($this->handler)(new DisableTotpCommand($user->getId()->toString(), '000000'));
        } catch (RuntimeException) {
            // Expected
        }

        $this->assertSame('JBSWY3DPEHPK3PXP', $user->getTotpSecret());
    }
}
