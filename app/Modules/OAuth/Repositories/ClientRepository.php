<?php

declare(strict_types=1);

namespace App\Modules\OAuth\Repositories;

use App\Models\OAuth\Client;
use App\Modules\OAuth\Contracts\ClientRepositoryInterface;
use App\Modules\OAuth\Entities\ClientEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;

class ClientRepository implements ClientRepositoryInterface
{
    public function getClientEntity($clientIdentifier): ?ClientEntityInterface
    {
        $client = Client::where('id', $clientIdentifier)
            ->where('revoked', false)
            ->first();

        if (!$client) {
            return null;
        }

        $clientEntity = new ClientEntity();
        $clientEntity->setIdentifier($client->id);
        $clientEntity->setName($client->name);
        $clientEntity->setRedirectUri($client->redirect);
        $clientEntity->setConfidential($client->isConfidential());

        return $clientEntity;
    }

    public function validateClient($clientIdentifier, $clientSecret, $grantType): bool
    {
        $client = Client::where('id', $clientIdentifier)
            ->where('revoked', false)
            ->first();

        if (!$client) {
            return false;
        }

        // For device code flow, we don't validate client secret for public clients
        if ($grantType === 'urn:ietf:params:oauth:grant-type:device_code') {
            return !$client->isConfidential() || hash_equals($client->secret, $clientSecret ?? '');
        }

        // For confidential clients, always validate secret
        if ($client->isConfidential()) {
            return hash_equals($client->secret, $clientSecret ?? '');
        }

        return true;
    }
}
