<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\User;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;

#[OA\Schema(
    schema: 'VerifyEmailRequest',
    required: ['token'],
    properties: [
        new OA\Property(property: 'token', type: 'string', example: 'verification-token-abc123'),
    ],
)]
final readonly class VerifyEmailRequest
{
    public function __construct(
        #[NotBlank(message: 'Verification token is required.')]
        public string $token = '',
    ) {
    }
}
