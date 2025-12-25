<?php

declare(strict_types=1);

namespace App\Modules\Auth\OAuth\Repositories;

use App\Models\Auth\OAuth\Client;
use App\Models\Auth\OAuth\DeviceCode as DeviceCodeModel;
use App\Modules\Auth\OAuth\Contracts\DeviceCodeRepositoryInterface;
use App\Modules\Auth\OAuth\Contracts\ScopeRepositoryInterface;
use App\Modules\Auth\OAuth\Entities\DeviceCodeEntity;
use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;

class DeviceCodeRepository implements DeviceCodeRepositoryInterface
{
    public function __construct(
        private readonly ScopeRepositoryInterface $scopeRepository,
    )
    {
    }

    public function updateDeviceCode(DeviceCodeEntityInterface $deviceCodeEntity): void
    {
        DeviceCodeModel::whereDeviceCode($deviceCodeEntity->getIdentifier())->update([
            'last_polled_at' => $deviceCodeEntity->getLastPolledAt(),
        ]);
    }

    public function getNewDeviceCode(): DeviceCodeEntityInterface
    {
        return new DeviceCodeEntity();
    }

    public function persistDeviceCode(DeviceCodeEntityInterface $deviceCodeEntity): void
    {
        $deviceCode = DeviceCodeModel::whereDeviceCode($deviceCodeEntity->getIdentifier())->first();

        if ($deviceCode !== null) {
            throw UniqueTokenIdentifierConstraintViolationException::create();
        }

        // Look up client by public_id and get the internal ID for the foreign key
        $client = Client::wherePublicId($deviceCodeEntity->getClient()->getIdentifier())->firstOrFail();

        DeviceCodeModel::create([
            'device_code'               => $deviceCodeEntity->getIdentifier(),
            'user_code'                 => $deviceCodeEntity->getUserCode(),
            'client_id'                 => $client->id,
            'user_id'                   => $deviceCodeEntity->getUserIdentifier(),
            'scopes'                    => array_map(
                fn($scope) => $scope->getIdentifier(),
                $deviceCodeEntity->getScopes(),
            ),
            'verification_uri'          => $deviceCodeEntity->getVerificationUri(),
            'verification_uri_complete' => $deviceCodeEntity->getVerificationUriComplete(),
            'expires_at'                => $deviceCodeEntity->getExpiryDateTime(),
            'interval'                  => $deviceCodeEntity->getInterval(),
            'approved'                  => $deviceCodeEntity->getUserApproved(),
        ]);
    }

    public function getDeviceCodeEntityByDeviceCode(string $deviceCode): ?DeviceCodeEntityInterface
    {
        $deviceCodeModel = DeviceCodeModel::whereDeviceCode($deviceCode)->first();

        if (!$deviceCodeModel) {
            return null;
        }

        $deviceCodeEntity = new DeviceCodeEntity();
        $deviceCodeEntity->setIdentifier($deviceCodeModel->device_code);
        $deviceCodeEntity->setUserCode($deviceCodeModel->user_code);
        $deviceCodeEntity->setExpiryDateTime($deviceCodeModel->expires_at);
        $deviceCodeEntity->setUserIdentifier($deviceCodeModel->user_id);
        $deviceCodeEntity->setInterval($deviceCodeModel->interval);
        $deviceCodeEntity->setUserApproved($deviceCodeModel->approved);
        $deviceCodeEntity->setVerificationUri($deviceCodeModel->verification_uri);

        if ($deviceCodeModel->last_polled_at) {
            $deviceCodeEntity->setLastPolledAt($deviceCodeModel->last_polled_at);
        }

        // Set client
        $client = $deviceCodeModel->client;
        if ($client) {
            $deviceCodeEntity->setClient($client);
        }

        // Set scopes
        foreach ($deviceCodeModel->scopes ?? [] as $scope) {
            $scopeEntity = $this->scopeRepository->getScopeEntityByIdentifier($scope);
            if ($scopeEntity) {
                $deviceCodeEntity->addScope($scopeEntity);
            }
        }

        return $deviceCodeEntity;
    }

    public function revokeDeviceCode(string $codeId): void
    {
        DeviceCodeModel::whereDeviceCode($codeId)->update(['denied' => true]);
    }

    public function isDeviceCodeRevoked(string $codeId): bool
    {
        $deviceCode = DeviceCodeModel::whereDeviceCode($codeId)->first();

        if ($deviceCode === null) {
            return true;
        }

        return $deviceCode->denied;
    }
}
