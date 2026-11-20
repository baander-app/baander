<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource;

use App\Auth\Application\DTO\TokenResponseDTO;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'TokenResource',
    properties: [
        new OA\Property(property: 'accessToken', description: 'OAuth 2.0 access token', type: 'string'),
        new OA\Property(property: 'tokenType', description: 'Token type, always DPoP: the access token is bound to the client\'s DPoP key (RFC 9449)', type: 'string'),
        new OA\Property(property: 'expiresIn', description: 'Seconds until token expiry', type: 'integer'),
        new OA\Property(property: 'refreshToken', description: 'OAuth 2.0 refresh token', type: 'string', nullable: true),
    ],
)]
final class TokenResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof TokenResponseDTO);

        return [
            'accessToken' => $source->getAccessToken(),
            'tokenType' => $source->getTokenType(),
            'expiresIn' => $source->getExpiresIn(),
            'refreshToken' => $source->getRefreshToken(),
        ];
    }

    /**
     * Convert a raw RFC 6749 OAuth 2.0 token response (snake_case) to the
     * project-standard camelCase format returned by {@see from()}.
     *
     * @param array<string, mixed> $oauthResponse Raw JSON-decoded body from the League server
     *
     * @return array{accessToken: string, tokenType: string, expiresIn: int, refreshToken: string|null}
     */
    public static function fromOAuthResponse(array $oauthResponse): array
    {
        return [
            'accessToken' => $oauthResponse['access_token'] ?? '',
            'tokenType' => $oauthResponse['token_type'] ?? 'Bearer',
            'expiresIn' => $oauthResponse['expires_in'] ?? 0,
            'refreshToken' => $oauthResponse['refresh_token'] ?? null,
        ];
    }

    /**
     * @deprecated Use from() instead. Kept for backward compatibility during migration.
     */
    /** @return array{accessToken: string, tokenType: string, expiresIn: int, refreshToken: string|null} */
    public static function fromDto(TokenResponseDTO $dto): array
    {
        return self::from($dto);
    }
}
