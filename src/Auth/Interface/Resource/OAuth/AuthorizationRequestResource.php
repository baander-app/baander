<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource\OAuth;

use App\Auth\Application\DTO\AuthorizationRequestDTO;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AuthorizationRequestResource',
    description: 'A valid authorization request awaiting the signed-in user\'s decision.',
    required: ['client_id', 'client_name', 'client_type', 'scopes', 'redirect_uri', 'consent_required'],
    properties: [
        new OA\Property(property: 'client_id', type: 'string'),
        new OA\Property(property: 'client_name', type: 'string', example: 'Baander Player'),
        new OA\Property(property: 'client_type', type: 'string', enum: ['public', 'confidential', 'first_party', 'personal_access']),
        new OA\Property(property: 'scopes', description: 'The scopes an approval grants: the requested scopes Baander allows, or the default scopes when none are left', type: 'array', items: new OA\Items(type: 'string'), example: ['library', 'playlist']),
        new OA\Property(property: 'redirect_uri', description: 'The validated redirect URI the answer goes to', type: 'string', format: 'uri'),
        new OA\Property(property: 'consent_required', description: 'False only for first-party clients, whose requests the page may approve without asking', type: 'boolean'),
    ],
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
            'consent_required' => $source->consentRequired,
        ];
    }
}
