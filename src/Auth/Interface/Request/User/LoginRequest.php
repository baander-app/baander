<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\User;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;

#[OA\Schema(
    schema: 'LoginRequest',
    required: ['email', 'password'],
    properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'alice@example.com'),
        new OA\Property(property: 'password', type: 'string', example: '********'),
        new OA\Property(property: 'totpCode', description: 'Required when user has TOTP enabled', type: 'string', example: '123456'),
    ],
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
