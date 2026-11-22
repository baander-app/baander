<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

use App\Auth\Application\DTO\RevokedSessions;
use App\Auth\Application\Exception\CredentialsChangedException;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;

/**
 * Applies a credential change and ends the sessions it outlives, inside the caller's
 * transaction, and refuses a change whose own session another change already ended.
 *
 * Every credential change runs through revokeSessions(), as PasswordChanger and the operator's
 * TOTP reset do. It locks the user's row first, so the changes of one account take turns with
 * each other and with every token issuance and refresh, which lock the same row. Under the lock
 * it reads the session the change keeps as stored now: when another change ended that session
 * while this one waited, or its chain was revoked, the change is refused before it writes
 * anything and the caller's transaction rolls back. Two changes from the same session pass,
 * because the first moved that session to its new credential epoch.
 *
 * Then the caller's change runs and saves the aggregate the caller loaded. The credential epoch
 * advances, every access and refresh token of the user except the kept session's is revoked,
 * delegated tokens included, and the kept session moves to the new epoch, so it stays signed
 * in. The change is saved before the revocation because the revocation clears the entity
 * manager: an aggregate saved after it would be compared with the row as it is now and write
 * back every field it loaded before the lock, undoing what a change committed meanwhile wrote.
 *
 * After the caller's transaction commits, closeConnections() closes the WebSocket connections
 * of the revoked sessions. Closing earlier would let a reconnect pass on a token the commit has
 * not yet revoked; a close that fails is logged and left to the socket reconciliation.
 */
final readonly class CredentialChangeRevoker
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private AccessTokenRepositoryInterface $accessTokenRepository,
        private RefreshTokenRepositoryInterface $refreshTokenRepository,
        private ChainConnectionCloser $connectionCloser,
    ) {
    }

    /**
     * Requires an open transaction, which the caller commits with its credential change.
     *
     * @param string|null    $keptAccessTokenId the token id of the access token the change was
     *                                          requested with: its session stays signed in.
     *                                          Null, or a token of another user, keeps no session.
     * @param \Closure(): void $change          writes the new credential and saves the user; it
     *                                          runs under the lock once the change is allowed
     *
     * @throws CredentialsChangedException when the kept session was ended before the lock was granted
     */
    #[\NoDiscard('Close the revoked sessions\' connections once the transaction has committed.')]
    public function revokeSessions(User $user, ?string $keptAccessTokenId, \Closure $change): RevokedSessions
    {
        $userId = $user->getId();
        $credentialEpoch = $this->userRepository->lockCredentialEpoch($userId)
            ?? throw new \LogicException(sprintf('User %s vanished while its credentials changed.', $userId->toString()));
        $keep = $this->keptSession($user, $keptAccessTokenId);
        if ($keep !== null && !$this->accessTokenRepository->isSessionCurrent($keep, $credentialEpoch)) {
            throw new CredentialsChangedException();
        }

        $change();
        $credentialEpoch = $this->userRepository->advanceCredentialEpoch($userId);
        // Refresh rows before access rows, chain by chain, as every chain revocation locks
        // them, so a logout of one of these chains never waits on this change in reverse.
        $this->refreshTokenRepository->revokeForUser($userId, $keep);
        $accessTokenIds = $this->accessTokenRepository->revokeForUser($userId, $keep);
        if ($keep !== null) {
            $this->refreshTokenRepository->restampCredentialEpoch($keep, $credentialEpoch);
            $this->accessTokenRepository->restampCredentialEpoch($keep, $credentialEpoch);
        }

        return new RevokedSessions($userId, $accessTokenIds);
    }

    /** Closes the revoked sessions' WebSocket connections; call it after the transaction commits. */
    public function closeConnections(RevokedSessions $sessions): void
    {
        $this->connectionCloser->closeForUser($sessions->accessTokenIds, $sessions->userId);
    }

    private function keptSession(User $user, ?string $accessTokenId): ?AccessToken
    {
        if ($accessTokenId === null) {
            return null;
        }

        $token = TokenId::fromString($accessTokenId) |> $this->accessTokenRepository->findByTokenId(...);

        return $token?->getUser()?->getId()->equals($user->getId()) === true ? $token : null;
    }
}
