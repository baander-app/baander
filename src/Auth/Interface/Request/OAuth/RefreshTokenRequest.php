<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\OAuth;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;

#[OA\Schema(
    schema: 'RefreshTokenRequest',
    required: ['refreshToken'],
    properties: [
        new OA\Property(property: 'refreshToken', type: 'string', example: 'your-refresh-token'),
    ],
)]
final readonly class RefreshTokenRequest
{
    public function __construct(
        #[NotBlank(message: 'Refresh token is required.')]
        public string $refreshToken = '',
    ) {
    }
}
