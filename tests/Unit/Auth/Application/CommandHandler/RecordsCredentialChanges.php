<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Application\Port\StepUpAttemptThrottleInterface;
use App\Auth\Application\Port\TotpVerifierInterface;
use App\Auth\Application\Service\ChainConnectionCloser;
use App\Auth\Application\Service\CredentialChangeRevoker;
use App\Auth\Application\Service\StepUpVerifier;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\SignInGrant;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Port\LiveConnectionsPortInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use Psr\Log\NullLogger;

/**
 * Storage, step-up and transaction fakes for the handlers of a user's own credential changes.
 *
 * $credentialChanges records, in order, which session each change kept while it ended the
 * others ("end sessions keeping <token id>" or "keeping none"), each commit, each rollback and
 * each close of the ended sessions' connections ("close <token ids>", with " in transaction"
 * when the close ran before the commit). The step-up fakes accept the password
 * "current-password" and the code "123456", and count every attempt in $stepUpAttempts.
 */
trait RecordsCredentialChanges
{
    /** @var list<string> */
    private array $credentialChanges = [];
    private int $stepUpAttempts = 0;
    private bool $inTransaction = false;
    /** Whether storage still holds the requesting session as signed in when the change locks the account. */
    private bool $requestingSessionCurrent = true;
    /** Set to make the account's row lock time out, as when another change holds it too long. */
    private bool $accountLockTimesOut = false;
    /** @var list<AccessToken> */
    private array $storedSessions = [];

    /** @return string the token id of a session the user signed in with */
    private function signedInSession(User $user): string
    {
        $token = AccessToken::issue(Client::create('SPA', ['https://baander.app']), SignInGrant::Password, $user, chainId: ChainId::generate());
        $this->storedSessions[] = $token;

        return $token->getTokenId()->toString();
    }

    private function transaction(): TransactionPortInterface
    {
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(function (callable $operation): mixed {
            $this->inTransaction = true;
            try {
                $result = $operation();
                $this->credentialChanges[] = 'commit';

                return $result;
            } catch (\Throwable $failure) {
                $this->credentialChanges[] = 'rollback';

                throw $failure;
            } finally {
                $this->inTransaction = false;
            }
        });

        return $transaction;
    }

    private function stepUp(): StepUpVerifier
    {
        $throttle = $this->createStub(StepUpAttemptThrottleInterface::class);
        $throttle->method('acquire')->willReturnCallback(function (): void {
            ++$this->stepUpAttempts;
        });
        $hasher = $this->createStub(PasswordHasherInterface::class);
        $hasher->method('verify')->willReturnCallback(static fn (string $plain): bool => $plain === 'current-password');
        $totp = $this->createStub(TotpVerifierInterface::class);
        $totp->method('verifyAndSpend')->willReturnCallback(static fn (User $user, string $code): bool => $code === '123456');

        return new StepUpVerifier($throttle, $hasher, $totp, new NullLogger());
    }

    private function credentialChangeRevoker(): CredentialChangeRevoker
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('lockCredentialEpoch')->willReturnCallback(function (): int {
            if ($this->accountLockTimesOut) {
                throw new \RuntimeException('canceling statement due to lock timeout');
            }

            return 3;
        });
        $users->method('advanceCredentialEpoch')->willReturn(4);

        $accessTokens = $this->createStub(AccessTokenRepositoryInterface::class);
        $accessTokens->method('findByTokenId')->willReturnCallback(fn (TokenId $id): ?AccessToken => array_find(
            $this->storedSessions,
            static fn (AccessToken $token): bool => $token->getTokenId()->equals($id),
        ));
        $accessTokens->method('isSessionCurrent')->willReturnCallback(fn (): bool => $this->requestingSessionCurrent);
        $accessTokens->method('revokeForUser')->willReturnCallback(function (Uuid $userId, ?AccessToken $keep): array {
            $this->credentialChanges[] = 'end sessions keeping ' . ($keep?->getTokenId()->toString() ?? 'none');

            return ['other-session-token'];
        });

        $connections = $this->createStub(LiveConnectionsPortInterface::class);
        $connections->method('closeForAccessTokens')->willReturnCallback(function (array $accessTokenIds): int {
            $this->credentialChanges[] = 'close ' . implode(', ', $accessTokenIds) . ($this->inTransaction ? ' in transaction' : '');

            return count($accessTokenIds);
        });

        return new CredentialChangeRevoker(
            $users,
            $accessTokens,
            $this->createStub(RefreshTokenRepositoryInterface::class),
            new ChainConnectionCloser($connections, new NullLogger()),
        );
    }
}
