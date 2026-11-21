<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\OAuth;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\NotBlank;

#[OA\Schema(
    type: 'object',
    properties: [
        'refreshToken' => new OA\Schema(
            type: 'string',
            example: 'your-refresh-token',
            default: '',
        ),
    ],
    required: ['refreshToken'],
    component: 'RefreshTokenRequest',
)]
final readonly class RefreshTokenRequest
{
    public function __construct(
        #[NotBlank(message: 'Refresh token is required.')]
        public string $refreshToken = '',
    ) {
    }
}
