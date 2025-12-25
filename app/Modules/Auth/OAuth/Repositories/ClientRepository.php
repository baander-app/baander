<?php

declare(strict_types=1);

namespace App\Modules\Auth\OAuth\Repositories;

use App\Models\Auth\OAuth\Client;
use App\Modules\Auth\OAuth\Contracts\ClientRepositoryInterface;
use App\Modules\Auth\OAuth\Entities\ClientEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;

class ClientRepository implements ClientRepositoryInterface
{
    public function getClientEntity($clientIdentifier): ?ClientEntityInterface
    {
        $client = Client::wherePublicId($clientIdentifier)
            ->whereRevoked(false)
            ->first();

        if (!$client) {
            return null;
        }

        $clientEntity = new ClientEntity();
        $clientEntity->setIdentifier($client->public_id);
        $clientEntity->setName($client->name);
        $clientEntity->setRedirectUri($client->redirect);
        $clientEntity->setConfidential($client->confidential);
        $clientEntity->setFirstParty($client->first_party);

        return $clientEntity;
    }

    public function validateClient($clientIdentifier, $clientSecret, $grantType): bool
    {
        $client = Client::wherePublicId($clientIdentifier)
            ->whereRevoked(false)
            ->first();

        if (!$client) {
            return false;
        }

        // Device code flow clients are always public (no secret)
        if ($grantType === 'urn:ietf:params:oauth:grant-type:device_code') {
            return true;
        }

        // For confidential clients, validate secret
        if ($client->confidential) {
            return $clientSecret && hash_equals($client->secret, $clientSecret);
        }

        // Public clients don't require secret
        return true;
    }
}
