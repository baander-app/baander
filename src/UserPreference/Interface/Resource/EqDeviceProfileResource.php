<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Resource;

use App\Shared\Domain\Model\PublicId;
use App\UserPreference\Domain\Model\EqDeviceProfile;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'publicId' => new OA\Schema(
            description: 'Profile public identifier',
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
        'name' => new OA\Schema(description: 'Profile name', type: 'string'),
        'icon' => new OA\Schema(description: 'Icon identifier', type: 'string'),
        'deviceId' => new OA\Schema(
            description: 'Client audio-output token, independent of database identity',
            type: 'string',
            nullable: true,
        ),
        'payload' => new OA\Schema(description: 'EQ configuration payload', type: 'object'),
        'isDefault' => new OA\Schema(description: 'Whether this is the default profile', type: 'boolean'),
        'sortOrder' => new OA\Schema(description: 'Sort order', type: 'integer'),
        'version' => new OA\Schema(description: 'Profile version', type: 'integer'),
        'createdAt' => new OA\Schema(
            description: 'Creation timestamp',
            type: 'string',
            format: 'date-time',
        ),
        'updatedAt' => new OA\Schema(
            description: 'Last update timestamp',
            type: 'string',
            format: 'date-time',
        ),
    ],
    required: ['publicId', 'name', 'icon', 'payload', 'isDefault', 'sortOrder', 'version', 'createdAt', 'updatedAt'],
    component: 'EqDeviceProfileResource',
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
