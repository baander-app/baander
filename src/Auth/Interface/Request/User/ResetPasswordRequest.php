<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\User;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

#[OA\Schema(
    schema: 'ResetPasswordRequest',
    required: ['token', 'password'],
    properties: [
        new OA\Property(property: 'token', description: 'The password reset token.', type: 'string', maxLength: 255),
        new OA\Property(property: 'password', type: 'string', example: '********', maxLength: 255, minLength: 8),
    ],
)]
final readonly class ResetPasswordRequest
{
    public function __construct(
        #[NotBlank(message: 'Token is required.')]
        #[Length(max: 255)]
        public string $token = '',

        #[NotBlank(message: 'Password is required.')]
        #[Length(min: 8, max: 255, minMessage: 'Password must be at least {{ limit }} characters.')]
        public string $password = '',
    ) {
    }
}
