<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\Admin;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'email' => new OA\Schema(
            type: 'string',
            format: 'email',
            example: 'user@example.com',
            default: '',
        ),
        'password' => new OA\Schema(
            type: 'string',
            minLength: 8,
            maxLength: 255,
            example: '********',
            default: '',
        ),
        'name' => new OA\Schema(
            type: 'string',
            minLength: 1,
            maxLength: 255,
            example: 'Alice',
            default: '',
        ),
        'roles' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(
                type: 'string',
                enum: ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'],
            ),
            example: ['ROLE_USER'],
            default: ['ROLE_USER'],
        ),
    ],
    required: ['email', 'password', 'name'],
    component: 'AdminCreateUserRequest',
)]
final readonly class AdminCreateUserRequest
{
    /** @param array<array-key, string> $roles */
    public function __construct(
        #[Assert\NotBlank(message: 'Email is required.')]
        #[Assert\Email]
        public string $email = '',

        #[Assert\NotBlank(message: 'Password is required.')]
        #[Assert\Length(min: 8, max: 255)]
        public string $password = '',

        #[Assert\NotBlank(message: 'Name is required.', normalizer: 'trim')]
        #[Assert\Length(min: 1, max: 255)]
        public string $name = '',

        #[Assert\Choice(choices: ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], multiple: true)]
        public array $roles = ['ROLE_USER'],
    ) {
    }
}
