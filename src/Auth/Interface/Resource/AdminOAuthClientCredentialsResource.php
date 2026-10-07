<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource;

use App\Auth\Application\DTO\RegisteredClientDTO;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminOAuthClientCredentialsResource',
    description: 'A client right after registration or secret rotation. The secret is shown only in this response; Baander stores its SHA-256 digest.',
    required: ['clientId', 'name', 'type', 'redirectUris', 'revoked', 'createdAt', 'updatedAt', 'clientSecret'],
    properties: [
        new OA\Property(property: 'clientId', description: 'The OAuth client_id (public ID)', type: 'string', example: 'V1StGXR8_Z5jdHi6B-myT'),
        new OA\Property(property: 'name', type: 'string', example: 'Living room TV'),
        new OA\Property(property: 'type', type: 'string', enum: ['device', 'public', 'confidential']),
        new OA\Property(property: 'redirectUris', type: 'array', items: new OA\Items(type: 'string', format: 'uri')),
        new OA\Property(property: 'revoked', type: 'boolean'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'clientSecret', description: 'The client_secret of a confidential client; null for device and public clients', type: 'string', nullable: true),
    ],
)]
final class AdminOAuthClientCredentialsResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof RegisteredClientDTO);

        return [
            ...AdminOAuthClientResource::from($source->client),
            'clientSecret' => $source->secret,
        ];
    }
}
