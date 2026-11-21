<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource;

use App\Auth\Application\DTO\RegisteredClientDTO;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    description: 'A client right after registration or secret rotation. The secret is shown only in this response; Baander stores its SHA-256 digest.',
    type: 'object',
    properties: [
        'clientId' => new OA\Schema(
            description: 'The OAuth client_id (public ID)',
            type: 'string',
            example: 'V1StGXR8_Z5jdHi6B-myT',
        ),
        'name' => new OA\Schema(type: 'string', example: 'Living room TV'),
        'type' => new OA\Schema(
            type: 'string',
            enum: ['device', 'public', 'confidential'],
        ),
        'authMethod' => new OA\Schema(
            type: 'string',
            enum: ['none', 'client_secret_post', 'private_key_jwt'],
        ),
        'jwksUri' => new OA\Schema(
            description: 'Where a private_key_jwt client publishes its keys; null otherwise',
            type: 'string',
            format: 'uri',
            nullable: true,
        ),
        'redirectUris' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(type: 'string', format: 'uri'),
        ),
        'revoked' => new OA\Schema(type: 'boolean'),
        'createdAt' => new OA\Schema(type: 'string', format: 'date-time'),
        'updatedAt' => new OA\Schema(type: 'string', format: 'date-time'),
        'clientSecret' => new OA\Schema(
            description: 'The client_secret of a client_secret_post client; null for device, public and private_key_jwt clients',
            type: 'string',
            nullable: true,
        ),
    ],
    required: [
        'clientId',
        'name',
        'type',
        'authMethod',
        'jwksUri',
        'redirectUris',
        'revoked',
        'createdAt',
        'updatedAt',
        'clientSecret',
    ],
    component: 'AdminOAuthClientCredentialsResource',
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
