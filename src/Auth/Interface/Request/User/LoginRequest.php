<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\User;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;

#[OA\Schema(
    type: 'object',
    properties: [
        'email' => new OA\Schema(
            type: 'string',
            format: 'email',
            example: 'alice@example.com',
            default: '',
        ),
        'password' => new OA\Schema(
            type: 'string',
            example: '********',
            default: '',
        ),
        'totpCode' => new OA\Schema(
            description: 'Required when user has TOTP enabled',
            type: 'string',
            nullable: true,
            example: '123456',
            default: null,
        ),
    ],
    required: ['email', 'password'],
    component: 'LoginRequest',
)]
final readonly class LoginRequest
{
    public function __construct(
        #[NotBlank(message: 'Email is required.')]
        #[Email]
        public string $email = '',

        #[NotBlank(message: 'Password is required.')]
        public string $password = '',

        #[Length(min: 6, max: 6, groups: ['totp'])]
        public ?string $totpCode = null,
    )
    {
    }
}
