<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Resource;

use App\UserPreference\Domain\Model\EqDeviceProfile;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'EqDeviceProfileResource',
    properties: [
        new OA\Property(property: 'publicId', description: 'Profile public identifier', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'name', description: 'Profile name', type: 'string'),
        new OA\Property(property: 'icon', description: 'Icon identifier', type: 'string'),
        new OA\Property(property: 'deviceId', description: 'Client audio-output token, independent of database identity', type: 'string', nullable: true),
        new OA\Property(property: 'payload', description: 'EQ configuration payload', type: 'object'),
        new OA\Property(property: 'isDefault', description: 'Whether this is the default profile', type: 'boolean'),
        new OA\Property(property: 'sortOrder', description: 'Sort order', type: 'integer'),
        new OA\Property(property: 'version', description: 'Profile version', type: 'integer'),
        new OA\Property(property: 'createdAt', description: 'Creation timestamp', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', description: 'Last update timestamp', type: 'string', format: 'date-time'),
    ],
)]
final class EqDeviceProfileResource
{
    /**
     * @return array{publicId: string, name: string, icon: string, deviceId: string|null, payload: array<array-key, mixed>, isDefault: bool, sortOrder: int, version: int, createdAt: string, updatedAt: string}
     */
    public static function from(EqDeviceProfile $model): array
    {
        return [
            'publicId' => $model->getPublicId()->toString(),
            'name' => $model->getName(),
            'icon' => $model->getIcon(),
            'deviceId' => $model->getDeviceId(),
            'payload' => $model->getPayload(),
            'isDefault' => $model->isDefault(),
            'sortOrder' => $model->getSortOrder(),
            'version' => $model->getVersion(),
            'createdAt' => $model->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $model->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }
}
