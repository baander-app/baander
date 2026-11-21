<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\OAuth;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\NotBlank;

#[OA\Schema(
    type: 'object',
    properties: [
        'token' => new OA\Schema(
            type: 'string',
            example: 'access-token-to-revoke',
            default: '',
        ),
        'tokenTypeHint' => new OA\Schema(
            description: 'Hint for the token type (e.g. "access_token" or "refresh_token")',
            type: 'string',
            default: '',
        ),
    ],
    required: ['token'],
    component: 'RevokeTokenRequest',
)]
final readonly class RevokeTokenRequest
{
    public function __construct(
        #[NotBlank(message: 'Token is required.')]
        public string $token = '',
        public string $tokenTypeHint = '',
    ) {
    }
}
