<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\OAuth;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;

#[OA\Schema(
    schema: 'RevokeTokenRequest',
    required: ['token'],
    properties: [
        new OA\Property(property: 'token', type: 'string', example: 'access-token-to-revoke'),
        new OA\Property(property: 'tokenTypeHint', description: 'Hint for the token type (e.g. "access_token" or "refresh_token")', type: 'string'),
    ],
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
