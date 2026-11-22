<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource\OAuth;

use App\Auth\Application\DTO\AuthorizationRequestDTO;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    description: 'A valid authorization request awaiting the signed-in user\'s decision.',
    type: 'object',
    properties: [
        'client_id' => new OA\Schema(type: 'string'),
        'client_name' => new OA\Schema(type: 'string', example: 'Baander Player'),
        'client_type' => new OA\Schema(
            type: 'string',
            enum: ['public', 'confidential', 'personal_access'],
        ),
        'scopes' => new OA\Schema(
            description: 'The scopes an approval grants: the requested scopes Baander allows, or the default scopes when none are left',
            type: 'array',
            items: new OA\Schema(type: 'string'),
            example: ['library', 'playlist'],
        ),
        'redirect_uri' => new OA\Schema(
            description: 'The validated redirect URI the answer goes to',
            type: 'string',
            format: 'uri',
        ),
    ],
    required: ['client_id', 'client_name', 'client_type', 'scopes', 'redirect_uri'],
    component: 'AuthorizationRequestResource',
)]
final class AuthorizationRequestResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof AuthorizationRequestDTO);

        return [
            'client_id' => $source->clientId,
            'client_name' => $source->clientName,
            'client_type' => $source->clientType,
            'scopes' => $source->scopes,
            'redirect_uri' => $source->redirectUri,
        ];
    }
}
