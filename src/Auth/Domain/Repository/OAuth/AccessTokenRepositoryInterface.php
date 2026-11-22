<?php

declare(strict_types=1);

namespace App\Auth\Domain\Repository\OAuth;

use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Shared\Domain\Model\Uuid;

interface AccessTokenRepositoryInterface
{
    public function save(AccessToken $accessToken, bool $flush = true): void;

    public function findByTokenId(TokenId $tokenId): ?AccessToken;

    /**
     * The token ids of every access token of the chain, in id order, without locking the rows.
     *
     * @return list<string>
     */
    public function findTokenIdsByChainId(ChainId $chainId): array;

    /**
     * Which of these token ids belong to a chain that still has a live access token: one that
     * is stored, not revoked and not expired on the database clock. A rotation revokes the
     * access token it replaces, so a socket opened with that token stays open while its chain
     * lives; the chain is dead once every access token of it is revoked or expired. An id the
     * database never stored, or one whose account is disabled, is not returned. The list may be long: it is sent in chunks.
     *
     * @param list<string> $tokenIds
     *
     * @return list<string>
     */
    public function findTokenIdsOfLiveChains(array $tokenIds): array;

    /** Revokes every access token of the chain after locking the chain's rows in id order. */
    public function revokeByChainId(ChainId $chainId): void;

    /**
     * Revokes the tokens with these ids that are not revoked yet and returns how many rows
     * changed. Leaves the entity manager's managed objects in place.
     *
     * @param list<Uuid> $ids
     */
    public function revokeByIds(array $ids): int;

    /**
     * Revokes every access token issued to the user. When $keep is given, that token and
     * the tokens of its refresh chain stay valid.
     *
     * Returns the token ids of every access token of the chains it revoked, including tokens a
     * rotation revoked earlier, whose WebSocket connections may still be open; a revoked token
     * outside any chain counts as its own chain. Clears the entity manager, so later reads see
     * the revocation; a caller saves its own aggregates before this call.
     *
     * @return list<string>
     */
    public function revokeForUser(Uuid $userId, ?AccessToken $keep = null): array;

    /**
     * Whether the token's session is still signed in under this credential epoch, as stored
     * now: its refresh chain, or the token alone when it has none, holds an access token that
     * is not revoked, and the token carries $credentialEpoch. A rotation revokes the token it
     * replaces and leaves the session signed in; a logout, a revocation of the chain and a
     * credential change made from another session end it.
     */
    public function isSessionCurrent(AccessToken $token, int $credentialEpoch): bool;

    /**
     * The credential epoch the token was issued under, read from storage; null when the token
     * is unknown or its user's credentials changed after it was issued (the user's epoch moved
     * past the token's).
     */
    public function currentCredentialEpoch(TokenId $tokenId): ?int;

    /**
     * Stamps $keep and the access tokens of its chain with the credential epoch a credential
     * change moved the user to, so the session that made the change stays signed in.
     */
    public function restampCredentialEpoch(AccessToken $keep, int $credentialEpoch): void;

    /** Revoke every active access token issued to the client. */
    public function revokeByClientId(Uuid $clientId): void;
}
