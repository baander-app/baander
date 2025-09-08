<?php

declare(strict_types=1);

namespace App\Modules\OAuth\Repositories;

use App\Models\OAuth\Scope;
use App\Modules\OAuth\Contracts\ScopeRepositoryInterface;
use App\Modules\OAuth\Entities\ScopeEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;

class ScopeRepository implements ScopeRepositoryInterface
{
    public function getScopeEntityByIdentifier($identifier): ?ScopeEntityInterface
    {
        $scope = Scope::find($identifier);

        if (!$scope) {
            return null;
        }

        $scopeEntity = new ScopeEntity();
        $scopeEntity->setIdentifier($scope->id);
        $scopeEntity->setDescription($scope->description);

        return $scopeEntity;
    }

    public function finalizeScopes(array $scopes, $grantType, ClientEntityInterface $clientEntity, $userIdentifier = null): array
    {
        // Here you can implement scope finalization logic
        // For now, we'll just return the requested scopes
        return $scopes;
    }
}
