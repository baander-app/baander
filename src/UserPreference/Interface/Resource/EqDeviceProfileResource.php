<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Resource;

use App\UserPreference\Infrastructure\Doctrine\Entity\EqDeviceProfileEntity;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'EqDeviceProfileResource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid', description: 'Profile UUID'),
        new OA\Property(property: 'name', type: 'string', description: 'Profile name'),
        new OA\Property(property: 'icon', type: 'string', description: 'Icon identifier'),
        new OA\Property(property: 'deviceId', type: 'string', format: 'uuid', nullable: true, description: 'Device UUID'),
        new OA\Property(property: 'payload', type: 'object', description: 'EQ configuration payload'),
        new OA\Property(property: 'isDefault', type: 'boolean', description: 'Whether this is the default profile'),
        new OA\Property(property: 'sortOrder', type: 'integer', description: 'Sort order'),
        new OA\Property(property: 'version', type: 'integer', description: 'Profile version'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', description: 'Creation timestamp'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time', description: 'Last update timestamp'),
    ],
)]
final class EqDeviceProfileResource
{
    /**
     * @return array{id: string, name: string, icon: string, deviceId: string|null, payload: array, isDefault: bool, sortOrder: int, version: int, createdAt: string, updatedAt: string}
     */
    public static function from(EqDeviceProfileEntity $entity): array
    {
        return [
            'id' => $entity->getId()->toString(),
            'name' => $entity->getName(),
            'icon' => $entity->getIcon(),
            'deviceId' => $entity->getDeviceId(),
            'payload' => $entity->getPayload(),
            'isDefault' => $entity->isDefault(),
            'sortOrder' => $entity->getSortOrder(),
            'version' => $entity->getVersion(),
            'createdAt' => $entity->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $entity->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }
}
