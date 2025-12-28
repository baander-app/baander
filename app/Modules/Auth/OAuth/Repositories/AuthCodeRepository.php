<?php

declare(strict_types=1);

namespace App\Modules\Auth\OAuth\Repositories;

use App\Models\Auth\OAuth\AuthCode;
use App\Models\Auth\OAuth\Client;
use App\Modules\Auth\OAuth\Contracts\AuthCodeRepositoryInterface;
use App\Modules\Auth\OAuth\Entities\AuthCodeEntity;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;

class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return new AuthCodeEntity();
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        // Look up client by public_id and get the internal ID for the foreign key
        $client = Client::wherePublicId($authCodeEntity->getClient()->getIdentifier())->firstOrFail();

        AuthCode::create([
            'code_id'    => $authCodeEntity->getIdentifier(), // OAuth server ID
            'user_id'    => $authCodeEntity->getUserIdentifier(),
            'client_id'  => $client->id,
            'scopes'     => array_map(fn(ScopeEntityInterface $scope) => $scope->getIdentifier(), $authCodeEntity->getScopes()),
            'revoked'    => false,
            'expires_at' => $authCodeEntity->getExpiryDateTime(),
        ]);
    }

    public function revokeAuthCode($codeId): void
    {
        AuthCode::whereCodeId($codeId)->update(['revoked' => true]);
    }

    public function isAuthCodeRevoked($codeId): bool
    {
        $authCode = AuthCode::whereCodeId($codeId)->first();

        return $authCode === null || $authCode->isRevoked();
    }
}
