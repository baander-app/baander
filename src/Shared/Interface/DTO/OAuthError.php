<?php

declare(strict_types=1);

namespace App\Shared\Interface\DTO;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'OAuthError',
    description: 'OAuth2 error response',
    properties: [
        new OA\Property(property: 'error', type: 'string', example: 'invalid_grant'),
        new OA\Property(property: 'error_description', type: 'string', example: 'The provided authorization code is invalid.'),
        new OA\Property(property: 'error_uri', type: 'string', nullable: true),
    ],
)]
final readonly class OAuthError
{
}
