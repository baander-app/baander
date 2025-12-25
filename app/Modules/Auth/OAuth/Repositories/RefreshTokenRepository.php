<?php

declare(strict_types=1);

namespace App\Modules\Auth\OAuth\Repositories;

use App\Models\Auth\OAuth\RefreshToken;
use App\Models\Auth\OAuth\Token;
use App\Modules\Auth\OAuth\Contracts\RefreshTokenRepositoryInterface;
use App\Modules\Auth\OAuth\Entities\RefreshTokenEntity;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;

class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function getNewRefreshToken(): RefreshTokenEntityInterface
    {
        return new RefreshTokenEntity();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        // Find the access token by its OAuth server identifier
        $accessToken = Token::whereTokenId($refreshTokenEntity->getAccessToken()->getIdentifier())->first();

        RefreshToken::create([
            'token_id'        => $refreshTokenEntity->getIdentifier(), // OAuth server ID
            'access_token_id' => $accessToken?->id, // Database ID
            'revoked'         => false,
            'expires_at'      => $refreshTokenEntity->getExpiryDateTime(),
        ]);
    }

    public function revokeRefreshToken($tokenId): void
    {
        RefreshToken::whereTokenId($tokenId)->update(['revoked' => true]);
    }

    public function isRefreshTokenRevoked($tokenId): bool
    {
        $refreshToken = RefreshToken::whereTokenId($tokenId)->first();

        return $refreshToken === null || $refreshToken->isRevoked();
    }
}
