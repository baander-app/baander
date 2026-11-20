<?php

declare(strict_types=1);

namespace App\Discovery\Interface\Resource;

use App\Discovery\Domain\Model\ServerInstance;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ServerInstanceResource',
    properties: [
        new OA\Property(property: 'publicId', description: 'Public identifier', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'serverUrl', description: 'Server base URL', type: 'string', format: 'uri'),
        new OA\Property(property: 'name', description: 'Server display name', type: 'string'),
        new OA\Property(property: 'version', description: 'Server version', type: 'string'),
        new OA\Property(property: 'status', description: 'Server status', type: 'string', enum: ['online', 'offline', 'maintenance']),
        new OA\Property(property: 'lastHeartbeatAt', description: 'Last heartbeat timestamp', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'createdAt', description: 'Registration timestamp', type: 'string', format: 'date-time'),
    ],
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
