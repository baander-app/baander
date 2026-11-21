<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource\OAuth;

use App\Auth\Application\DTO\TokenResponseDTO;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    description: 'Successful token response (RFC 6749 section 5.1) with a DPoP-bound access token (RFC 9449).',
    type: 'object',
    properties: [
        'access_token' => new OA\Schema(type: 'string'),
        'token_type' => new OA\Schema(
            type: 'string',
            enum: ['DPoP'],
        ),
        'expires_in' => new OA\Schema(
            description: 'Seconds until the access token expires',
            type: 'integer',
            example: 3600,
        ),
        'refresh_token' => new OA\Schema(type: 'string'),
        'scope' => new OA\Schema(
            description: 'Space-separated scopes the access token carries',
            type: 'string',
            example: 'library playlist',
        ),
    ],
    required: ['access_token', 'token_type', 'expires_in', 'refresh_token', 'scope'],
    component: 'OAuthTokenResource',
)]
final class OAuthTokenResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof TokenResponseDTO);

        return [
            'access_token' => $source->getAccessToken(),
            'token_type' => $source->getTokenType(),
            'expires_in' => $source->getExpiresIn(),
            'refresh_token' => $source->getRefreshToken(),
            'scope' => implode(' ', $source->getScopes()),
        ];
    }
}
