<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\User;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\NotBlank;

#[OA\Schema(
    type: 'object',
    properties: [
        'token' => new OA\Schema(
            type: 'string',
            example: 'verification-token-abc123',
            default: '',
        ),
    ],
    required: ['token'],
    component: 'VerifyEmailRequest',
)]
final readonly class VerifyEmailRequest
{
    public function __construct(
        #[NotBlank(message: 'Verification token is required.')]
        public string $token = '',
    ) {
    }
}
