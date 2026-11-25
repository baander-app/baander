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
use App\Auth\Infrastructure\Security\Totp\SodiumTotpSecretCipher;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class EnableTotpHandlerTest extends TestCase
{
    use RecordsCredentialChanges;

    private const string SECRET = 'JBSWY3DPEHPK3PXP';
    private const int STEP = 58_000_000;

    private PendingTotpSecrets $pendingSecrets;
    private User $user;
    /** @var list<string> the user's saves and spent steps, each marked when it ran inside the transaction */
    private array $writes = [];

    protected function setUp(): void
    {
        $this->user = User::register(new Email('test@baander.app'), 'hashed', 'Alice');
        $this->pendingSecrets = new PendingTotpSecrets(new ArrayAdapter(), self::pendingCipher());
    }

    /**
     * The secret is stored sealed and the confirming code's step is spent in the same
     * transaction, so the code cannot also sign in. The session that sent the code stays signed
     * in, the others end, and their connections close once the change has committed.
     */
    public function testACodeFromThePendingSecretTurnsTotpOnAndEndsEveryOtherSession(): void
    {
        $this->pendingSecrets->hold($this->user->getId(), self::SECRET);
        $session = $this->signedInSession($this->user);

        $this->handler()(new EnableTotpCommand($this->user->getId()->toString(), '123456', $session));

        self::assertSame('sealed:' . self::SECRET, $this->user->getTotpSecretCiphertext());
        self::assertSame(['save in transaction', 'spend step ' . self::STEP . ' in transaction'], $this->writes);
        self::assertSame([
            'end sessions keeping ' . $session,
            'commit',
            'close other-session-token',
        ], $this->credentialChanges);
        self::assertNull($this->pendingSecrets->find($this->user->getId()), 'The pending secret is used once.');
    }

    /** The ended sessions' connections close even when the cache cannot forget the pending secret. */
    public function testAPendingSecretThatCannotBeForgottenStillClosesTheOtherSessionsConnections(): void
    {
        $this->pendingSecrets = new PendingTotpSecrets(new class extends ArrayAdapter {
            #[\Override]
            public function deleteItem(mixed $key): bool
            {
                throw new \RuntimeException('Redis server went away');
            }
        }, self::pendingCipher());
        $this->pendingSecrets->hold($this->user->getId(), self::SECRET);
        $session = $this->signedInSession($this->user);

        try {
            $this->handler()(new EnableTotpCommand($this->user->getId()->toString(), '123456', $session));
        } catch (\RuntimeException $exception) {
            self::assertSame('Redis server went away', $exception->getMessage(), 'Only the cache failure may reach the caller.');
        }

        self::assertTrue($this->user->hasTotp());
        self::assertSame([
            'end sessions keeping ' . $session,
            'commit',
            'close other-session-token',
        ], $this->credentialChanges);
    }

    /** A change that timed out waiting for the account's row rolled back, so the same setup can be confirmed again. */
    public function testAChangeThatRollsBackKeepsThePendingSecret(): void
    {
        $this->pendingSecrets->hold($this->user->getId(), self::SECRET);
        $this->accountLockTimesOut = true;

        try {
            $this->handler()(new EnableTotpCommand($this->user->getId()->toString(), '123456'));
            self::fail('The lock timeout must reach the caller.');
        } catch (\RuntimeException $exception) {
            self::assertSame('canceling statement due to lock timeout', $exception->getMessage());
        }

        self::assertSame(['rollback'], $this->credentialChanges);
        self::assertSame(self::SECRET, $this->pendingSecrets->find($this->user->getId()));
    }

    public function testWithoutAPendingSetupTheRequestIsRefused(): void
    {
        $this->assertRefused(TotpChangeRefusal::SetupMissing);
        self::assertFalse($this->user->hasTotp());
    }

    public function testACodeThatMatchesNoStepIsRefusedAndKeepsThePendingSecret(): void
    {
        $this->pendingSecrets->hold($this->user->getId(), self::SECRET);

        $this->assertRefused(TotpChangeRefusal::InvalidCode, code: '000000');
        self::assertFalse($this->user->hasTotp());
        self::assertSame(self::SECRET, $this->pendingSecrets->find($this->user->getId()));
    }

    public function testWhileTotpIsOnTheRequestIsRefused(): void
    {
        $this->user->enableTotp('sealed-current-secret');
        $this->pendingSecrets->hold($this->user->getId(), self::SECRET);

        $this->assertRefused(TotpChangeRefusal::AlreadyEnabled);
        self::assertSame('sealed-current-secret', $this->user->getTotpSecretCiphertext());
    }

    private function assertRefused(TotpChangeRefusal $refusal, string $code = '123456'): void
    {
        try {
            $this->handler()(new EnableTotpCommand($this->user->getId()->toString(), $code));
            self::fail('The request must be refused.');
        } catch (TotpChangeRefusedException $exception) {
            self::assertSame($refusal, $exception->refusal);
        }
        self::assertSame([], $this->writes);
        self::assertSame([], $this->credentialChanges);
    }

    private static function pendingCipher(): SodiumTotpSecretCipher
    {
        return new SodiumTotpSecretCipher(base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    }

    private function handler(): EnableTotpHandler
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByUuid')->willReturn($this->user);
        $users->method('save')->willReturnCallback(function (): void {
            $this->writes[] = 'save' . ($this->inTransaction ? ' in transaction' : '');
        });
        $users->method('spendTotpStep')->willReturnCallback(function (Uuid $id, int $step): bool {
            $this->writes[] = 'spend step ' . $step . ($this->inTransaction ? ' in transaction' : '');

            return true;
        });
        $totp = $this->createStub(TotpVerifierInterface::class);
        $totp->method('matchStep')->willReturnCallback(
            static fn (string $secret, string $code): ?int => $secret === self::SECRET && $code === '123456' ? self::STEP : null,
        );
        $cipher = $this->createStub(TotpSecretCipherInterface::class);
        $cipher->method('seal')->willReturnCallback(static fn (string $secret): string => 'sealed:' . $secret);

        return new EnableTotpHandler($users, $totp, $cipher, $this->pendingSecrets, $this->credentialChangeRevoker(), $this->transaction());
    }
}
