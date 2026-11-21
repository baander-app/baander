<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource\OAuth;

use App\Auth\Application\DTO\PendingDeviceAuthorizationDTO;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'userCode' => new OA\Schema(
            description: 'The normalized user code',
            type: 'string',
            example: 'BCDF-GHJK',
        ),
        'clientId' => new OA\Schema(type: 'string'),
        'clientName' => new OA\Schema(type: 'string', example: 'Living room TV'),
        'scopes' => new OA\Schema(
            description: 'The scopes an approval grants',
            type: 'array',
            items: new OA\Schema(type: 'string'),
        ),
        'expiresAt' => new OA\Schema(
            type: 'string',
            format: 'date-time',
            nullable: true,
        ),
    ],
    required: ['userCode', 'clientId', 'clientName', 'scopes', 'expiresAt'],
    component: 'PendingDeviceAuthorizationResource',
)]
final class PendingDeviceAuthorizationResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof PendingDeviceAuthorizationDTO);

        return [
            'userCode' => $source->userCode,
            'clientId' => $source->clientId,
            'clientName' => $source->clientName,
            'scopes' => $source->scopes,
            'expiresAt' => $source->expiresAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
