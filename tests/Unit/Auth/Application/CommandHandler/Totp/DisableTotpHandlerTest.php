<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\Totp\DisableTotpCommand;
use App\Auth\Application\CommandHandler\Totp\DisableTotpHandler;
use App\Auth\Application\Exception\StepUpFailedException;
use App\Auth\Application\Exception\TotpChangeRefusal;
use App\Auth\Application\Exception\TotpChangeRefusedException;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Turning one's own TOTP off needs the current password and a current code. It keeps the
 * requesting session, ends the others and closes their connections once it has committed.
 */
final class DisableTotpHandlerTest extends TestCase
{
    use RecordsCredentialChanges;

    private User $user;

    protected function setUp(): void
    {
        $this->user = User::register(new Email('test@baander.app'), 'hashed', 'Alice');
    }

    public function testThePasswordAndCodeTurnTotpOffAndEndEveryOtherSession(): void
    {
        $this->user->enableTotp('sealed-secret');
        $session = $this->signedInSession($this->user);
        $saved = [];

        $this->handler($saved)(new DisableTotpCommand($this->user->getId()->toString(), 'current-password', '123456', $session));

        self::assertFalse($this->user->hasTotp());
        self::assertSame([true], $saved, 'TOTP is turned off inside the transaction.');
        self::assertSame([
            'end sessions keeping ' . $session,
            'commit',
            'close other-session-token',
        ], $this->credentialChanges);
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function mismatches(): iterable
    {
        yield 'no password' => ['', '123456'];
        yield 'wrong password' => ['not-the-password', '123456'];
        yield 'no code' => ['current-password', null];
        yield 'wrong code' => ['current-password', '654321'];
    }

    #[DataProvider('mismatches')]
    public function testAMismatchIsRefusedAndChangesNothing(string $password, ?string $code): void
    {
        $this->user->enableTotp('sealed-secret');
        $saved = [];

        try {
            $this->handler($saved)(new DisableTotpCommand($this->user->getId()->toString(), $password, $code));
            self::fail('A mismatch must be refused.');
        } catch (StepUpFailedException) {
        }

        self::assertTrue($this->user->hasTotp());
        self::assertSame([], $saved);
        self::assertSame([], $this->credentialChanges);
        self::assertSame(1, $this->stepUpAttempts);
    }

    public function testWithoutTotpTheRequestIsRefusedBeforeAnyStepUpAttempt(): void
    {
        $saved = [];

        try {
            $this->handler($saved)(new DisableTotpCommand($this->user->getId()->toString(), 'current-password', '123456'));
            self::fail('The request must be refused.');
        } catch (TotpChangeRefusedException $exception) {
            self::assertSame(TotpChangeRefusal::NotEnabled, $exception->refusal);
        }

        self::assertSame(0, $this->stepUpAttempts);
        self::assertSame([], $this->credentialChanges);
    }

    public function testAnUnknownUserIsNotFound(): void
    {
        $this->expectException(UserNotFoundException::class);

        $saved = [];
        $this->handler($saved, known: false)(new DisableTotpCommand(Uuid::v4()->toString(), 'current-password', '123456'));
    }

    /** @param list<bool> $saved whether each save of the user ran inside the transaction */
    private function handler(array &$saved, bool $known = true): DisableTotpHandler
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByUuid')->willReturn($known ? $this->user : null);
        $users->method('save')->willReturnCallback(function () use (&$saved): void {
            $saved[] = $this->inTransaction;
        });

        return new DisableTotpHandler($users, $this->stepUp(), $this->credentialChangeRevoker(), $this->transaction());
    }
}
