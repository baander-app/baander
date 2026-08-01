<?php

declare(strict_types=1);

namespace App\Discovery\Interface\Resource;

use App\Discovery\Domain\Model\PairingSession;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PairingSessionResource',
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid', description: 'Pairing session UUID'),
        new OA\Property(property: 'publicId', type: 'string', format: 'uuid', description: 'Public identifier'),
        new OA\Property(property: 'serverPublicId', type: 'string', format: 'uuid', description: 'Server public ID'),
        new OA\Property(property: 'serverUrl', type: 'string', format: 'uri', description: 'Server base URL'),
        new OA\Property(property: 'serverName', type: 'string', description: 'Server display name'),
        new OA\Property(property: 'pairingCode', type: 'string', description: 'Human-readable pairing code'),
        new OA\Property(property: 'method', type: 'string', enum: ['qr_code', 'email_url', 'server_code'], description: 'Authentication method'),
        new OA\Property(property: 'expiresAt', type: 'string', format: 'date-time', nullable: true, description: 'Expiration timestamp'),
        new OA\Property(property: 'completedAt', type: 'string', format: 'date-time', nullable: true, description: 'Completion timestamp'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', description: 'Creation timestamp'),
    ],
)]
final class PairingSessionResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof PairingSession);

        return [
            'uuid' => $source->getId()->toString(),
            'publicId' => $source->getPublicId()->toString(),
            'serverPublicId' => $source->getServerPublicId()->toString(),
            'serverUrl' => $source->getServerUrl(),
            'serverName' => $source->getServerName(),
            'pairingCode' => $source->getPairingCode()->toString(),
            'method' => $source->getMethod()->value,
            'expiresAt' => $source->getExpiresAt()?->format(\DateTimeInterface::ATOM),
            'completedAt' => $source->getCompletedAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
