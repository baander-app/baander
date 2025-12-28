<?php

declare(strict_types=1);

namespace App\Modules\Auth\OAuth\Repositories;

use App\Models\Auth\OAuth\Scope;
use App\Modules\Auth\OAuth\Contracts\ScopeRepositoryInterface;
use App\Modules\Auth\OAuth\Entities\ScopeEntity;
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

    public function finalizeScopes(array $scopes, $grantType, ClientEntityInterface $clientEntity, $userIdentifier = null, ?string $authCodeId = null): array
    {
        // Validate that all requested scopes exist
        $validScopes = [];
        foreach ($scopes as $scope) {
            $scopeEntity = $this->getScopeEntityByIdentifier($scope->getIdentifier());
            if ($scopeEntity) {
                $validScopes[] = $scopeEntity;
            }
        }

        return $validScopes;
    }
}
