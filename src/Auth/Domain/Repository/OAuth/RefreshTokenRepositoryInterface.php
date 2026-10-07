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
     * @return RefreshToken[]
     */
    public function findByChainId(ChainId $chainId): array;

    public function revokeByChainId(ChainId $chainId): void;

    /**
     * Revokes every refresh token issued with one of the user's access tokens. When $keep is
     * given, refresh tokens issued with it or in its refresh chain stay valid.
     */
    public function revokeForUser(Uuid $userId, ?AccessToken $keep = null): void;
}
