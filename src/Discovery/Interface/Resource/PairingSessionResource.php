<?php

declare(strict_types=1);

namespace App\Discovery\Interface\Resource;

use App\Discovery\Domain\Model\PairingSession;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PairingSessionResource',
    properties: [
        new OA\Property(property: 'publicId', description: 'Public identifier', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'serverPublicId', description: 'Server public ID', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'serverUrl', description: 'Server base URL', type: 'string', format: 'uri'),
        new OA\Property(property: 'serverName', description: 'Server display name', type: 'string'),
        new OA\Property(property: 'pairingCode', description: 'Human-readable pairing code', type: 'string'),
        new OA\Property(property: 'method', description: 'Authentication method', type: 'string', enum: ['qr_code', 'email_url', 'server_code']),
        new OA\Property(property: 'expiresAt', description: 'Expiration timestamp', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'completedAt', description: 'Completion timestamp', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'createdAt', description: 'Creation timestamp', type: 'string', format: 'date-time'),
    ],
)]
final class PairingSessionResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof PairingSession);

        return [
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
