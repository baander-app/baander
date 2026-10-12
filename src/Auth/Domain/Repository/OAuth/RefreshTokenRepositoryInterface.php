<?php

declare(strict_types=1);

namespace App\Auth\Domain\Repository\OAuth;

use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Shared\Domain\Model\Uuid;

interface RefreshTokenRepositoryInterface
{
    public function save(RefreshToken $refreshToken, bool $flush = true): void;

    public function findByTokenId(TokenId $tokenId): ?RefreshToken;

    /**
     * Atomically consume a refresh token by its token ID.
     *
     * Returns the token only if the row was updated (was unused and valid).
     * Returns null if the token is unknown, already used, revoked, or expired.
     */
    public function consumeByTokenId(TokenId $tokenId): ?RefreshToken;

    /**
     * Locks the token's row FOR UPDATE and returns its state as of the lock, so a decision
     * taken on it holds until the transaction ends. Requires an open transaction.
     */
    public function lockByTokenId(TokenId $tokenId): ?RefreshToken;

    /**
     * Locks the tokens issued with the given one as their predecessor FOR UPDATE, in issuance
     * order (by id), and returns their state as of the lock. Requires an open transaction.
     *
     * @return list<RefreshToken>
     */
    public function lockSuccessors(Uuid $refreshTokenId): array;

    /**
     * Whether the token was consumed within the last $seconds seconds, measured on the
     * database clock; false for a token that was never consumed.
     */
    public function consumedWithin(Uuid $refreshTokenId, int $seconds): bool;

    /** Whether the chain still holds a refresh token that is not revoked. */
    public function chainHoldsLiveToken(ChainId $chainId): bool;

    /**
     * Revokes the tokens with these ids that are not revoked yet and returns how many rows
     * changed. Leaves the entity manager's managed objects in place, so it can run inside
     * a transaction that still persists new tokens.
     *
     * @param list<Uuid> $ids
     */
    public function revokeByIds(array $ids): int;

    /**
     * @return RefreshToken[]
     */
    public function findByChainId(ChainId $chainId): array;

    /**
     * Revokes every refresh token of the chain after locking the chain's rows in id order,
     * so chain revocations and a grace that locks a token before its successors never
     * wait on each other in opposite orders.
     */
    public function revokeByChainId(ChainId $chainId): void;

    /**
     * Revokes every refresh token issued with one of the user's access tokens. When $keep is
     * given, refresh tokens issued with it or in its refresh chain stay valid.
     */
    public function revokeForUser(Uuid $userId, ?AccessToken $keep = null): void;

    /** Revoke every active refresh token whose access token was issued to the client. */
    public function revokeByClientId(Uuid $clientId): void;
}
