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
 * Applies a credential change and ends the sessions it outlives, inside the caller's transaction.
 *
 * Holds the user row's FOR NO KEY UPDATE lock (lockCredentialEpoch()), the lock token issuance and
 * refresh also take. Under it, a change whose kept session was revoked or left behind on an older
 * credential epoch is refused before anything is written. Otherwise the change runs, the epoch
 * advances, every other access and refresh token of the user is revoked, and the kept session
 * moves to the new epoch.
 *
 * The change saves before the revocation, because the revocation clears the entity manager.
 * closeConnections() runs after commit, so a reconnect cannot pass on a token not yet revoked.
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
