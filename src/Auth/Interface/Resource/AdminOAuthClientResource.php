<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource;

use App\Auth\Domain\Model\OAuth\Client;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminOAuthClientResource',
    required: ['clientId', 'name', 'type', 'redirectUris', 'revoked', 'createdAt', 'updatedAt'],
    properties: [
        new OA\Property(property: 'clientId', description: 'The OAuth client_id (public ID)', type: 'string', example: 'V1StGXR8_Z5jdHi6B-myT'),
        new OA\Property(property: 'name', type: 'string', example: 'Living room TV'),
        new OA\Property(property: 'type', type: 'string', enum: ['device', 'public', 'confidential', 'first_party']),
        new OA\Property(property: 'redirectUris', type: 'array', items: new OA\Items(type: 'string', format: 'uri')),
        new OA\Property(property: 'revoked', type: 'boolean'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
    ],
)]
final class AdminOAuthClientResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof Client);

        return [
            'clientId' => $source->getPublicId()->toString(),
            'name' => $source->getName(),
            'type' => $source->getType()->value,
            'redirectUris' => array_values($source->getRedirectUris()),
            'revoked' => $source->isRevoked(),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $source->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
