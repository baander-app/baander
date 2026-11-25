<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\Totp\StartTotpSetupCommand;
use App\Auth\Application\CommandHandler\Totp\StartTotpSetupHandler;
use App\Auth\Application\Exception\EmailUnverifiedException;
use App\Auth\Application\Exception\StepUpFailedException;
use App\Auth\Application\Exception\TotpChangeRefusal;
use App\Auth\Application\Exception\TotpChangeRefusedException;
use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Application\Port\StepUpAttemptThrottleInterface;
use App\Auth\Application\Port\TotpVerifierInterface;
use App\Auth\Application\Service\PendingTotpSecrets;
use App\Auth\Application\Service\StepUpVerifier;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Security\Totp\SodiumTotpSecretCipher;
use App\Shared\Domain\Model\Email;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class StartTotpSetupHandlerTest extends TestCase
{
    private User $user;
    private int $stepUpAttempts = 0;

    protected function setUp(): void
    {
        $this->user = User::register(new Email('test@baander.app'), 'hashed', 'Alice');
        $this->user->verifyEmail();
    }

    public function testAnUnverifiedAddressIsRefusedBeforeStepUpSpendsAnAttempt(): void
    {
        $user = User::register(new Email('unverified@baander.app'), 'hashed', 'Alice');
        $this->user = $user;
        $pending = new PendingTotpSecrets(new ArrayAdapter(), self::cipher());

        try {
            $this->handler($pending, ['FIRSTSECRET'])(new StartTotpSetupCommand($user->getId()->toString(), 'current-password'));
            self::fail('Setup must be refused for an unverified address.');
        } catch (EmailUnverifiedException $exception) {
            $this->assertSame(['reason' => 'email_unverified'], $exception->details);
        }
        $this->assertSame(0, $this->stepUpAttempts);
        $this->assertNull($pending->find($user->getId()));
    }

    public function testEachSetupReplacesThePendingSecret(): void
    {
        $pending = new PendingTotpSecrets(new ArrayAdapter(), self::cipher());
        $handler = $this->handler($pending, ['FIRSTSECRET', 'SECONDSECRET']);

        $first = $handler(new StartTotpSetupCommand($this->user->getId()->toString(), 'current-password'));
        $second = $handler(new StartTotpSetupCommand($this->user->getId()->toString(), 'current-password'));

        $this->assertSame(['FIRSTSECRET', 'SECONDSECRET'], [$first, $second]);
        $this->assertSame('SECONDSECRET', $pending->find($this->user->getId()));
    }

    public function testThePendingSecretExpiresAfterTenMinutes(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->expects($this->once())->method('set')->with($this->logicalNot($this->stringContains('FIRSTSECRET')))->willReturnSelf();
        $item->expects($this->once())->method('expiresAfter')->with(600)->willReturnSelf();
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);
        $pool->expects($this->once())->method('save')->with($item)->willReturn(true);

        $this->handler(new PendingTotpSecrets($pool, self::cipher()), ['FIRSTSECRET'])(new StartTotpSetupCommand($this->user->getId()->toString(), 'current-password'));
    }

    /** TOTP is turned off first, which takes a current code. */
    public function testWhileTotpIsOnSetupIsRefused(): void
    {
        $this->user->enableTotp('sealed-secret');
        $pending = new PendingTotpSecrets(new ArrayAdapter(), self::cipher());

        try {
            $this->handler($pending, ['FIRSTSECRET'])(new StartTotpSetupCommand($this->user->getId()->toString(), 'current-password'));
            self::fail('Setup must be refused while TOTP is on.');
        } catch (TotpChangeRefusedException $exception) {
            $this->assertSame(TotpChangeRefusal::AlreadyEnabled, $exception->refusal);
        }
        $this->assertNull($pending->find($this->user->getId()));
    }

    public function testAWrongPasswordHoldsNoSecret(): void
    {
        $pending = new PendingTotpSecrets(new ArrayAdapter(), self::cipher());

        try {
            $this->handler($pending, ['FIRSTSECRET'])(new StartTotpSetupCommand($this->user->getId()->toString(), 'not-the-password'));
            self::fail('Setup must be refused without the current password.');
        } catch (StepUpFailedException) {
        }
        $this->assertNull($pending->find($this->user->getId()));
    }

    private static function cipher(): SodiumTotpSecretCipher
    {
        return new SodiumTotpSecretCipher(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES) |> base64_encode(...));
    }

    /** @param list<string> $secrets The secrets generated, in order */
    private function handler(PendingTotpSecrets $pending, array $secrets): StartTotpSetupHandler
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByUuid')->willReturn($this->user);
        $verifier = $this->createStub(TotpVerifierInterface::class);
        $verifier->method('generateSecret')->willReturnOnConsecutiveCalls(...$secrets);

        $hasher = $this->createStub(PasswordHasherInterface::class);
        $hasher->method('verify')->willReturnCallback(static fn (string $plain): bool => $plain === 'current-password');
        $throttle = $this->createStub(StepUpAttemptThrottleInterface::class);
        $throttle->method('acquire')->willReturnCallback(function (): void {
            ++$this->stepUpAttempts;
        });
        $stepUp = new StepUpVerifier($throttle, $hasher, $verifier, new NullLogger());

        return new StartTotpSetupHandler($users, $verifier, $pending, $stepUp);
    }
}
