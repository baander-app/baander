<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource\OAuth;

use App\Auth\Application\DTO\PendingDeviceAuthorizationDTO;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PendingDeviceAuthorizationResource',
    required: ['userCode', 'clientId', 'clientName', 'scopes', 'expiresAt'],
    properties: [
        new OA\Property(property: 'userCode', description: 'The normalized user code', type: 'string', example: 'BCDF-GHJK'),
        new OA\Property(property: 'clientId', type: 'string'),
        new OA\Property(property: 'clientName', type: 'string', example: 'Living room TV'),
        new OA\Property(property: 'scopes', description: 'The scopes an approval grants', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'expiresAt', type: 'string', format: 'date-time', nullable: true),
    ],
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
