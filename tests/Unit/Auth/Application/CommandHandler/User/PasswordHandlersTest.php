<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\ChangePasswordCommand;
use App\Auth\Application\Command\User\ResetPasswordCommand;
use App\Auth\Application\Command\User\SetUserPasswordCommand;
use App\Auth\Application\CommandHandler\User\ChangePasswordHandler;
use App\Auth\Application\CommandHandler\User\ResetPasswordHandler;
use App\Auth\Application\CommandHandler\User\SetUserPasswordHandler;
use App\Auth\Application\Exception\PasswordPolicyException;
use App\Auth\Application\Exception\PasswordResetException;
use App\Auth\Application\Exception\StepUpFailedException;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Application\Port\PasswordResetTokenRepositoryInterface;
use App\Auth\Application\Port\StepUpAttemptThrottleInterface;
use App\Auth\Application\Port\TotpVerifierInterface;
use App\Auth\Application\Service\ChainConnectionCloser;
use App\Auth\Application\Service\CredentialChangeRevoker;
use App\Auth\Application\Service\PasswordChanger;
use App\Auth\Application\Service\StepUpVerifier;
use App\Auth\Application\Service\UserLookup;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\SignInGrant;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\Passkey\Passkey;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Port\LiveConnectionsPortInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/** The three password paths share PasswordChanger; these tests pin who may use it and which session survives. */
final class PasswordHandlersTest extends TestCase
{
    private User $user;
    /** @var array<string, User> */
    private array $users = [];
    /** @var list<string> */
    private array $keptSessions = [];
    private ?AccessToken $storedToken = null;
    private bool $active = false;
    /** @var list<bool> Whether each password hash ran inside the reset transaction. */
    private array $hashedInTransaction = [];
    /** @var list<Passkey> */
    private array $passkeys = [];
    /** @var list<bool> whether each passkey was removed inside the transaction */
    private array $removedPasskeys = [];
    /** @var list<bool> whether each TOTP step was cleared inside the transaction */
    private array $totpStepsCleared = [];
    /** @var list<bool> whether each close of the revoked sessions' connections ran inside the reset transaction */
    private array $closedInTransaction = [];
    private int $stepUpAttempts = 0;

    protected function setUp(): void
    {
        $this->user = User::register(new Email('owner@baander.app'), 'hashed:current-password', 'Owner');
        $this->users[$this->user->getId()->toString()] = $this->user;
    }

    public function testResetRedeemsTheTokenAndSignsOutEverySession(): void
    {
        $handler = $this->resetHandler($this->user->getId());

        $handler(new ResetPasswordCommand('raw-token', 'new-password'));

        self::assertSame('hashed:new-password', $this->user->getPassword());
        self::assertSame(['none'], $this->keptSessions);
        self::assertSame([false], $this->hashedInTransaction, 'The new password is hashed before the reset transaction opens.');
        self::assertSame([false], $this->closedInTransaction, 'The connections close after the reset transaction commits.');
    }

    public function testResetRejectsATokenThatRedeemsToNoUser(): void
    {
        $handler = $this->resetHandler(null);

        $this->expectExceptionObject(PasswordResetException::invalidToken());
        $handler(new ResetPasswordCommand('raw-token', 'new-password'));
    }

    public function testResetRejectsATokenWhoseUserNoLongerExists(): void
    {
        $handler = $this->resetHandler(Uuid::generate());

        $this->expectExceptionObject(PasswordResetException::invalidToken());
        $handler(new ResetPasswordCommand('raw-token', 'new-password'));
    }

    public function testResetRejectsADisabledAccountWithTheSameError(): void
    {
        $this->user->disable();
        $handler = $this->resetHandler($this->user->getId());

        try {
            $handler(new ResetPasswordCommand('raw-token', 'new-password'));
            self::fail('A disabled account cannot be reset.');
        } catch (PasswordResetException $e) {
            self::assertSame(PasswordResetException::invalidToken()->getMessage(), $e->getMessage());
        }
        self::assertSame('hashed:current-password', $this->user->getPassword());
    }

    public function testAResetToANeverVerifiedAddressClaimsTheAccountInTheResetTransaction(): void
    {
        $this->user->enableTotp('sealed-secret');
        $this->user->requestEmailChange(new Email('elsewhere@baander.app'));
        $this->passkeys = [$this->passkey(), $this->passkey()];

        $this->resetHandler($this->user->getId())(new ResetPasswordCommand('raw-token', 'new-password'));

        self::assertSame('hashed:new-password', $this->user->getPassword());
        self::assertTrue($this->user->isEmailVerified());
        self::assertFalse($this->user->hasTotp());
        self::assertNull($this->user->getPendingEmail());
        self::assertSame([true], $this->totpStepsCleared);
        self::assertSame([true, true], $this->removedPasskeys);
        self::assertSame(['none'], $this->keptSessions);
    }

    public function testAResetToAVerifiedAddressKeepsTheAuthenticatorsButDropsAPendingChange(): void
    {
        $this->user->verifyEmail();
        $this->user->enableTotp('sealed-secret');
        $this->user->requestEmailChange(new Email('elsewhere@baander.app'));
        $this->passkeys = [$this->passkey()];

        $this->resetHandler($this->user->getId())(new ResetPasswordCommand('raw-token', 'new-password'));

        self::assertTrue($this->user->hasTotp());
        self::assertNull($this->user->getPendingEmail());
        self::assertSame([], $this->totpStepsCleared);
        self::assertSame([], $this->removedPasskeys);
    }

    public function testOperatorResetFindsTheUserByEmailOrPublicIdAndSignsOutEverySession(): void
    {
        $handler = new SetUserPasswordHandler(new UserLookup($this->userRepository()), $this->changer(), $this->revoker());

        $handler(new SetUserPasswordCommand('Owner@Baander.app', 'by-email-password'));
        self::assertSame('hashed:by-email-password', $this->user->getPassword());
        $handler(new SetUserPasswordCommand($this->user->getPublicId()->toString(), 'by-uuid-password'));
        self::assertSame('hashed:by-uuid-password', $this->user->getPassword());

        self::assertSame(['none', 'none'], $this->keptSessions);
    }

    public function testOperatorResetReportsAnUnknownUser(): void
    {
        $handler = new SetUserPasswordHandler(new UserLookup($this->userRepository()), $this->changer(), $this->revoker());

        foreach (['nobody@baander.app', Uuid::generate()->toString(), 'not-a-uuid'] as $identifier) {
            try {
                $handler(new SetUserPasswordCommand($identifier, 'new-password'));
                self::fail('An unknown user must be reported.');
            } catch (UserNotFoundException $e) {
                self::assertStringContainsString($identifier, $e->getMessage());
            }
        }
    }

    public function testChangeKeepsTheRequestingSession(): void
    {
        $this->storedToken = AccessToken::issue(Client::create('SPA', ['https://baander.app']), SignInGrant::Password, $this->user);

        $this->changeHandler()(new ChangePasswordCommand(
            $this->user->getId()->toString(),
            'current-password',
            'new-password',
            currentAccessTokenId: $this->storedToken->getTokenId()->toString(),
        ));

        self::assertSame('hashed:new-password', $this->user->getPassword());
        self::assertSame([$this->storedToken->getTokenId()->toString()], $this->keptSessions);
    }

    public function testChangeDoesNotKeepAnotherUsersToken(): void
    {
        $other = User::register(new Email('other@baander.app'), 'hashed:x', 'Other');
        $this->storedToken = AccessToken::issue(Client::create('SPA', ['https://baander.app']), SignInGrant::Password, $other);

        $this->changeHandler()(new ChangePasswordCommand(
            $this->user->getId()->toString(),
            'current-password',
            'new-password',
            currentAccessTokenId: $this->storedToken->getTokenId()->toString(),
        ));

        self::assertSame(['none'], $this->keptSessions);
    }

    public function testChangeRequiresTheCurrentPassword(): void
    {
        $this->assertChangeRefusedByStepUp(new ChangePasswordCommand($this->user->getId()->toString(), 'wrong-password', 'new-password'));
    }

    /** With TOTP on, the right password without a code gets the answer of a wrong password. */
    public function testWithTotpOnAChangeNeedsACurrentCode(): void
    {
        $this->user->enableTotp('sealed-secret');

        $this->assertChangeRefusedByStepUp(new ChangePasswordCommand($this->user->getId()->toString(), 'current-password', 'new-password'));
        $this->assertChangeRefusedByStepUp(new ChangePasswordCommand($this->user->getId()->toString(), 'current-password', 'new-password', '654321'));

        $this->changeHandler()(new ChangePasswordCommand($this->user->getId()->toString(), 'current-password', 'new-password', '123456'));
        self::assertSame('hashed:new-password', $this->user->getPassword());
    }

    public function testANewPasswordOutsideThePolicyIsRefusedBeforeAnyStepUpAttempt(): void
    {
        try {
            $this->changeHandler()(new ChangePasswordCommand($this->user->getId()->toString(), 'current-password', 'short'));
            self::fail('A password outside the policy must be refused.');
        } catch (PasswordPolicyException) {
        }

        self::assertSame(0, $this->stepUpAttempts);
        self::assertSame('hashed:current-password', $this->user->getPassword());
    }

    private function assertChangeRefusedByStepUp(ChangePasswordCommand $command): void
    {
        try {
            $this->changeHandler()($command);
            self::fail('The change must be refused.');
        } catch (StepUpFailedException) {
        }

        self::assertSame('hashed:current-password', $this->user->getPassword());
        self::assertSame([], $this->keptSessions);
    }

    private function changeHandler(): ChangePasswordHandler
    {
        return new ChangePasswordHandler($this->userRepository(), $this->stepUp(), $this->changer(), $this->revoker());
    }

    /** Accepts the password "current-password" and, when TOTP is on, the code "123456"; counts every attempt. */
    private function stepUp(): StepUpVerifier
    {
        $throttle = $this->createStub(StepUpAttemptThrottleInterface::class);
        $throttle->method('acquire')->willReturnCallback(function (): void {
            ++$this->stepUpAttempts;
        });
        $totp = $this->createStub(TotpVerifierInterface::class);
        $totp->method('verifyAndSpend')->willReturnCallback(static fn (User $user, string $code): bool => $code === '123456');

        return new StepUpVerifier($throttle, $this->hasher(), $totp, new NullLogger());
    }

    private function resetHandler(?Uuid $redeemsTo): ResetPasswordHandler
    {
        $users = $this->userRepository();
        $passkeys = $this->createStub(PasskeyRepositoryInterface::class);
        $passkeys->method('forUser')->willReturnCallback(fn (): array => $this->passkeys);
        $passkeys->method('remove')->willReturnCallback(function (): void {
            $this->removedPasskeys[] = $this->active;
        });
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(function (callable $operation): mixed {
            $this->active = true;
            try {
                return $operation();
            } finally {
                $this->active = false;
            }
        });

        return new ResetPasswordHandler(
            $this->resetTokens($redeemsTo),
            $users,
            $this->changer(),
            $this->revoker(),
            $passkeys,
            $transaction,
        );
    }

    private function passkey(): Passkey
    {
        return Passkey::create(new Uuid(), 'Key', bin2hex(random_bytes(16)), [], 0);
    }

    private function resetTokens(?Uuid $redeemsTo): PasswordResetTokenRepositoryInterface
    {
        $tokens = $this->createMock(PasswordResetTokenRepositoryInterface::class);
        $tokens->expects($this->once())->method('redeem')->with('raw-token')->willReturn($redeemsTo);

        return $tokens;
    }

    private function userRepository(): UserRepositoryInterface
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByPublicId')->willReturnCallback(function (\App\Shared\Domain\Model\PublicId $id): ?User {
            foreach ($this->users as $user) {
                if ($id->equals($user->getPublicId())) { return $user; }
            }
            return null;
        });
        $users->method('findByUuid')->willReturnCallback(fn (Uuid $id): ?User => $this->users[$id->toString()] ?? null);
        $users->method('lockAndFind')->willReturnCallback(fn (Uuid $id): ?User => $this->users[$id->toString()] ?? null);
        $users->method('findByEmail')->willReturnCallback(
            fn (Email $email): ?User => $email->toString() === $this->user->getEmail() ? $this->user : null,
        );
        $users->method('clearTotpStep')->willReturnCallback(function (): void {
            $this->totpStepsCleared[] = $this->active;
        });

        return $users;
    }

    private function hasher(): PasswordHasherInterface
    {
        $hasher = $this->createStub(PasswordHasherInterface::class);
        $hasher->method('hash')->willReturnCallback(function (string $plain): string {
            $this->hashedInTransaction[] = $this->active;

            return 'hashed:' . $plain;
        });
        $hasher->method('verify')->willReturnCallback(static fn (string $plain, string $hash): bool => 'hashed:' . $plain === $hash);

        return $hasher;
    }

    private function changer(): PasswordChanger
    {
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());

        return new PasswordChanger(
            $this->hasher(),
            $this->createStub(UserRepositoryInterface::class),
            $this->revoker(),
            $transaction,
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(PasswordResetTokenRepositoryInterface::class),
        );
    }

    /** Ends the user's sessions, keeping the stored token when it is the requesting one, and records each close. */
    private function revoker(): CredentialChangeRevoker
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('lockCredentialEpoch')->willReturn(0);
        $accessTokens = $this->createStub(AccessTokenRepositoryInterface::class);
        $accessTokens->method('findByTokenId')->willReturnCallback(
            fn (TokenId $id): ?AccessToken => $this->storedToken?->getTokenId()->equals($id) === true ? $this->storedToken : null,
        );
        $accessTokens->method('isSessionCurrent')->willReturn(true);
        $accessTokens->method('revokeForUser')->willReturnCallback(function (Uuid $userId, ?AccessToken $keep): array {
            self::assertTrue($this->user->getId()->equals($userId));
            $this->keptSessions[] = $keep?->getTokenId()->toString() ?? 'none';

            return [];
        });
        $connections = $this->createStub(LiveConnectionsPortInterface::class);
        $connections->method('closeForAccessTokens')->willReturnCallback(function (): int {
            $this->closedInTransaction[] = $this->active;

            return 0;
        });

        return new CredentialChangeRevoker(
            $users,
            $accessTokens,
            $this->createStub(RefreshTokenRepositoryInterface::class),
            new ChainConnectionCloser($connections, new NullLogger()),
        );
    }
}
