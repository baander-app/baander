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

    public function revokeByChainId(ChainId $chainId): void;

    /**
     * Revokes every access token issued to the user. When $keep is given, that token and
     * the tokens of its refresh chain stay valid.
     */
    public function revokeForUser(Uuid $userId, ?AccessToken $keep = null): void;

    /** Revoke every active access token issued to the client. */
    public function revokeByClientId(Uuid $clientId): void;
}
