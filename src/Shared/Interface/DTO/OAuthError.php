<?php

declare(strict_types=1);

namespace App\Shared\Interface\DTO;

use OpenApi\Spec as OA;

#[OA\Schema(
    description: 'OAuth2 error response',
    type: 'object',
    properties: [
        'error' => new OA\Schema(type: 'string', example: 'invalid_grant'),
        'error_description' => new OA\Schema(type: 'string', example: 'The provided authorization code is invalid.'),
        'error_uri' => new OA\Schema(type: 'string', nullable: true),
    ],
    required: ['error', 'error_description'],
    component: 'OAuthError',
)]
final readonly class OAuthError
{
}
