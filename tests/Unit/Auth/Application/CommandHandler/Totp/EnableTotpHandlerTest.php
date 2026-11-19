<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\Totp\EnableTotpCommand;
use App\Auth\Application\CommandHandler\Totp\EnableTotpHandler;
use App\Auth\Application\Exception\TotpChangeRefusal;
use App\Auth\Application\Exception\TotpChangeRefusedException;
use App\Auth\Application\Port\TotpSecretCipherInterface;
use App\Auth\Application\Port\TotpVerifierInterface;
use App\Auth\Application\Service\PendingTotpSecrets;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Email;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class EnableTotpHandlerTest extends TestCase
{
    private const string SECRET = 'JBSWY3DPEHPK3PXP';

    private UserRepositoryInterface&MockObject $userRepository;
    private TotpVerifierInterface&Stub $totpVerifier;
    private PendingTotpSecrets $pendingSecrets;
    private EnableTotpHandler $handler;
    private User $user;

    protected function setUp(): void
    {
        $this->user = User::register(new Email('test@baander.app'), 'hashed', 'Alice');
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->userRepository->method('findByUuid')->willReturn($this->user);
        $this->totpVerifier = $this->createStub(TotpVerifierInterface::class);
        $cipher = $this->createStub(TotpSecretCipherInterface::class);
        $cipher->method('seal')->willReturnCallback(static fn (string $secret): string => 'sealed:' . $secret);
        $this->pendingSecrets = new PendingTotpSecrets(new ArrayAdapter());
        $this->handler = new EnableTotpHandler($this->userRepository, $this->totpVerifier, $cipher, $this->pendingSecrets);
    }

    /** The secret is stored sealed, and the confirming code's step is spent so it cannot also sign in. */
    public function testACodeFromThePendingSecretTurnsTotpOnAndSpendsItsStep(): void
    {
        $this->pendingSecrets->hold($this->user->getId(), self::SECRET);
        $this->totpVerifier->method('matchStep')->willReturnMap([[self::SECRET, '123456', 58_000_000]]);
        $this->userRepository->expects($this->once())->method('save')->with($this->user);
        $this->userRepository->expects($this->once())->method('spendTotpStep')->with($this->user->getId(), 58_000_000)->willReturn(true);

        ($this->handler)(new EnableTotpCommand($this->user->getId()->toString(), '123456'));

        $this->assertSame('sealed:' . self::SECRET, $this->user->getTotpSecretCiphertext());
        $this->assertNull($this->pendingSecrets->find($this->user->getId()), 'The pending secret is used once.');
    }

    public function testWithoutAPendingSetupTheRequestIsRefused(): void
    {
        $this->assertRefused(TotpChangeRefusal::SetupMissing);
        $this->assertFalse($this->user->hasTotp());
    }

    public function testACodeThatMatchesNoStepIsRefusedAndKeepsThePendingSecret(): void
    {
        $this->pendingSecrets->hold($this->user->getId(), self::SECRET);
        $this->totpVerifier->method('matchStep')->willReturn(null);

        $this->assertRefused(TotpChangeRefusal::InvalidCode);
        $this->assertFalse($this->user->hasTotp());
        $this->assertSame(self::SECRET, $this->pendingSecrets->find($this->user->getId()));
    }

    public function testWhileTotpIsOnTheRequestIsRefused(): void
    {
        $this->user->enableTotp('sealed-current-secret');
        $this->pendingSecrets->hold($this->user->getId(), self::SECRET);
        $this->totpVerifier->method('matchStep')->willReturn(58_000_000);

        $this->assertRefused(TotpChangeRefusal::AlreadyEnabled);
        $this->assertSame('sealed-current-secret', $this->user->getTotpSecretCiphertext());
    }

    private function assertRefused(TotpChangeRefusal $refusal): void
    {
        $this->userRepository->expects($this->never())->method('save');
        try {
            ($this->handler)(new EnableTotpCommand($this->user->getId()->toString(), '123456'));
            self::fail('The request must be refused.');
        } catch (TotpChangeRefusedException $exception) {
            $this->assertSame($refusal, $exception->refusal);
        }
    }
}
