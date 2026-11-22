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
        'name' => new OA\Schema(
            type: 'string',
            minLength: 1,
            maxLength: 255,
            example: 'Alice',
            default: '',
        ),
        'email' => new OA\Schema(
            type: 'string',
            format: 'email',
            maxLength: 255,
            example: 'alice@example.com',
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
    required: ['name', 'email', 'password'],
    component: 'RegisterRequest',
)]
final readonly class RegisterRequest
{
    public function __construct(
        #[NotBlank(message: 'Name is required.', normalizer: 'trim')]
        #[Length(min: 1, max: 255)]
        public string $name = '',

        #[NotBlank(message: 'Email is required.')]
        #[Email]
        public string $email = '',

        #[NotBlank(message: 'Password is required.')]
        #[Length(min: 8, max: 255)]
        public string $password = '',
    ) {
    }
}
