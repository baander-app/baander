<?php

declare(strict_types=1);

namespace App\Modules\Auth\OAuth\Repositories;

use App\Events\OAuth\AccessTokenCreatedEvent;
use App\Models\Auth\OAuth\Client;
use App\Models\Auth\OAuth\Token;
use App\Modules\Auth\OAuth\Contracts\AccessTokenRepositoryInterface;
use App\Modules\Auth\OAuth\Entities\AccessTokenEntity;
use Illuminate\Support\Facades\Event;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;

class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    private ?string $grantType = null;

    public function setGrantType(string $grantType): void
    {
        $this->grantType = $grantType;
    }

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

        $token = Token::create([
            'token_id'   => $accessTokenEntity->getIdentifier(), // OAuth server ID
            'user_id'    => $accessTokenEntity->getUserIdentifier(),
            'client_id'  => $client->id,
            'scopes'     => array_map(fn(ScopeEntityInterface $scope) => $scope->getIdentifier(), $accessTokenEntity->getScopes()),
            'revoked'    => false,
            'expires_at' => $accessTokenEntity->getExpiryDateTime(),
        ]);

        // Fire access token created event
        if ($token->user) {
            Event::dispatch(new AccessTokenCreatedEvent(
                $token,
                $token->user,
                $client,
                $this->grantType,
                $token->scopes ?? [],
            ));
        }
    }

    public function revokeAccessToken($tokenId): void
    {
        Token::whereTokenId($tokenId)->update(['revoked' => true]);
    }

    public function isAccessTokenRevoked($tokenId): bool
    {
        $token = Token::whereTokenId($tokenId)->first();

        return $token === null || $token->isRevoked();
    }
}
