<?php

declare(strict_types=1);

namespace App\Discovery\Interface\Resource;

use App\Discovery\Domain\Model\ServerInstance;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ServerInstanceResource',
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid', description: 'Server UUID'),
        new OA\Property(property: 'publicId', type: 'string', format: 'uuid', description: 'Public identifier'),
        new OA\Property(property: 'serverUrl', type: 'string', format: 'uri', description: 'Server base URL'),
        new OA\Property(property: 'name', type: 'string', description: 'Server display name'),
        new OA\Property(property: 'apiKey', type: 'string', description: 'Server API key (returned on registration only)'),
        new OA\Property(property: 'version', type: 'string', description: 'Server version'),
        new OA\Property(property: 'status', type: 'string', enum: ['online', 'offline', 'maintenance'], description: 'Server status'),
        new OA\Property(property: 'lastHeartbeatAt', type: 'string', format: 'date-time', nullable: true, description: 'Last heartbeat timestamp'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', description: 'Registration timestamp'),
    ],
)]
final class ServerInstanceResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof ServerInstance);

        return [
            'uuid' => $source->getId()->toString(),
            'publicId' => $source->getPublicId()->toString(),
            'serverUrl' => $source->getServerUrl(),
            'name' => $source->getName(),
            'apiKey' => $source->getApiKey(),
            'version' => $source->getVersion(),
            'status' => $source->getStatus()->value,
            'lastHeartbeatAt' => $source->getLastHeartbeatAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
