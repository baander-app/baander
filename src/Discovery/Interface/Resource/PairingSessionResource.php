<?php

declare(strict_types=1);

namespace App\Discovery\Interface\Resource;

use App\Discovery\Domain\Model\PairingSession;
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
        'serverPublicId' => new OA\Schema(
            description: 'Server public ID',
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
        'serverName' => new OA\Schema(description: 'Server display name', type: 'string'),
        'pairingCode' => new OA\Schema(description: 'Human-readable pairing code', type: 'string'),
        'method' => new OA\Schema(
            description: 'Authentication method',
            type: 'string',
            enum: ['qr_code', 'email_url', 'server_code'],
        ),
        'expiresAt' => new OA\Schema(
            description: 'Expiration timestamp',
            type: 'string',
            format: 'date-time',
            nullable: true,
        ),
        'completedAt' => new OA\Schema(
            description: 'Completion timestamp',
            type: 'string',
            format: 'date-time',
            nullable: true,
        ),
        'createdAt' => new OA\Schema(
            description: 'Creation timestamp',
            type: 'string',
            format: 'date-time',
        ),
    ],
    required: ['publicId', 'serverPublicId', 'serverUrl', 'serverName', 'pairingCode', 'method', 'createdAt'],
    component: 'PairingSessionResource',
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
