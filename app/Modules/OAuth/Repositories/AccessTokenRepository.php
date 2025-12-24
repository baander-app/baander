<?php

declare(strict_types=1);

namespace App\Modules\OAuth\Repositories;

use App\Models\OAuth\Client;
use App\Models\OAuth\Token;
use App\Modules\OAuth\Contracts\AccessTokenRepositoryInterface;
use App\Modules\OAuth\Entities\AccessTokenEntity;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;

class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, $userIdentifier = null): AccessTokenEntityInterface
    {
        $accessToken = new AccessTokenEntity();
        $accessToken->setClient($clientEntity);
        $accessToken->setUserIdentifier($userIdentifier);

        foreach ($scopes as $scope) {
            $accessToken->addScope($scope);
        }

        return $accessToken;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        // Look up client by public_id and get the internal ID for the foreign key
        $client = Client::wherePublicId($accessTokenEntity->getClient()->getIdentifier())->firstOrFail();

        Token::create([
            'token_id'   => $accessTokenEntity->getIdentifier(), // OAuth server ID
            'user_id'    => $accessTokenEntity->getUserIdentifier(),
            'client_id'  => $client->id,
            'scopes'     => array_map(fn(ScopeEntityInterface $scope) => $scope->getIdentifier(), $accessTokenEntity->getScopes()),
            'revoked'    => false,
            'expires_at' => $accessTokenEntity->getExpiryDateTime(),
        ]);
    }

    public function revokeAccessToken($tokenId): void
    {
        Token::whereId($tokenId)->update(['revoked' => true]);
    }

    public function isAccessTokenRevoked($tokenId): bool
    {
        $token = Token::whereId($tokenId)->first();

        return $token === null || $token->isRevoked();
    }
}
