<?php

declare(strict_types=1);

namespace App\Modules\OAuth\Repositories;

use App\Models\OAuth\RefreshToken;
use App\Models\OAuth\Token;
use App\Modules\OAuth\Contracts\RefreshTokenRepositoryInterface;
use App\Modules\OAuth\Entities\RefreshTokenEntity;
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
        $accessToken = Token::where('token_id', $refreshTokenEntity->getAccessToken()->getIdentifier())->first();

        RefreshToken::create([
            'token_id' => $refreshTokenEntity->getIdentifier(), // OAuth server ID
            'access_token_id' => $accessToken?->id, // Database ID
            'revoked' => false,
            'expires_at' => $refreshTokenEntity->getExpiryDateTime(),
        ]);
    }

    public function revokeRefreshToken($tokenId): void
    {
        RefreshToken::where('token_id', $tokenId)->update(['revoked' => true]);
    }

    public function isRefreshTokenRevoked($tokenId): bool
    {
        $refreshToken = RefreshToken::where('token_id', $tokenId)->first();

        return $refreshToken === null || $refreshToken->isRevoked();
    }
}
