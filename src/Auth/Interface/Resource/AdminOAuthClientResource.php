<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource;

use App\Auth\Domain\Model\OAuth\Client;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
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
            enum: ['device', 'public', 'confidential', 'first_party'],
        ),
        'authMethod' => new OA\Schema(
            description: 'How the client authenticates at the token and pushed-request endpoints: none for clients without credentials, client_secret_post with a secret, private_key_jwt with signed assertions',
            type: 'string',
            enum: ['none', 'client_secret_post', 'private_key_jwt'],
        ),
        'jwksUri' => new OA\Schema(
            description: 'Where a private_key_jwt client publishes its keys; null for an inline key set and for other clients',
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
    ],
    component: 'AdminOAuthClientResource',
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
            'authMethod' => $source->getAuthMethod()->value,
            'jwksUri' => $source->getKeySource()?->jwksUri(),
            'redirectUris' => array_values($source->getRedirectUris()),
            'revoked' => $source->isRevoked(),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $source->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
