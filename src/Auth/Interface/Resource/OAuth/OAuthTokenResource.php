<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource\OAuth;

use App\Auth\Application\DTO\TokenResponseDTO;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'OAuthTokenResource',
    description: 'Successful token response (RFC 6749 section 5.1) with a DPoP-bound access token (RFC 9449).',
    required: ['access_token', 'token_type', 'expires_in', 'refresh_token', 'scope'],
    properties: [
        new OA\Property(property: 'access_token', type: 'string'),
        new OA\Property(property: 'token_type', type: 'string', enum: ['DPoP']),
        new OA\Property(property: 'expires_in', description: 'Seconds until the access token expires', type: 'integer', example: 3600),
        new OA\Property(property: 'refresh_token', type: 'string'),
        new OA\Property(property: 'scope', description: 'Space-separated scopes the access token carries', type: 'string', example: 'library playlist'),
    ],
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
