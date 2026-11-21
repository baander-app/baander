<?php

declare(strict_types=1);

namespace App\Discovery\Interface\Resource;

use App\Discovery\Domain\Model\ServerInstance;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'publicId' => new OA\Schema(
            description: 'Public identifier',
            type: 'string',
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
        ),
        'serverUrl' => new OA\Schema(
            description: 'Server base URL',
            type: 'string',
            format: 'uri',
        ),
        'name' => new OA\Schema(description: 'Server display name', type: 'string'),
        'version' => new OA\Schema(description: 'Server version', type: 'string'),
        'status' => new OA\Schema(
            description: 'Server status',
            type: 'string',
            enum: ['online', 'offline', 'maintenance'],
        ),
        'lastHeartbeatAt' => new OA\Schema(
            description: 'Last heartbeat timestamp',
            type: 'string',
            format: 'date-time',
            nullable: true,
        ),
        'createdAt' => new OA\Schema(
            description: 'Registration timestamp',
            type: 'string',
            format: 'date-time',
        ),
    ],
    required: ['publicId', 'serverUrl', 'name', 'version', 'status', 'createdAt'],
    component: 'ServerInstanceResource',
)]
final class ServerInstanceResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof ServerInstance);

        return [
            'publicId' => $source->getPublicId()->toString(),
            'serverUrl' => $source->getServerUrl(),
            'name' => $source->getName(),
            'version' => $source->getVersion(),
            'status' => $source->getStatus()->value,
            'lastHeartbeatAt' => $source->getLastHeartbeatAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
