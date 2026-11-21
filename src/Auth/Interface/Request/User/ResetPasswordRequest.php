<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\User;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

#[OA\Schema(
    type: 'object',
    properties: [
        'token' => new OA\Schema(
            description: 'The password reset token.',
            type: 'string',
            maxLength: 255,
            default: '',
        ),
        'password' => new OA\Schema(
            type: 'string',
            minLength: 8,
            maxLength: 255,
            example: '********',
            default: '',
        ),
    ],
    required: ['token', 'password'],
    component: 'ResetPasswordRequest',
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
