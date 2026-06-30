<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\Admin;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'AdminCreateUserRequest',
    required: ['email', 'password', 'name'],
    properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com'),
        new OA\Property(property: 'password', type: 'string', example: '********', minLength: 8),
        new OA\Property(property: 'name', type: 'string', example: 'Alice'),
        new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string'), example: ['ROLE_USER']),
    ],
)]
final readonly class AdminCreateUserRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Email is required.')]
        #[Assert\Email]
        public string $email = '',

        #[Assert\NotBlank(message: 'Password is required.')]
        #[Assert\Length(min: 8, max: 255)]
        public string $password = '',

        #[Assert\NotBlank(message: 'Name is required.')]
        #[Assert\Length(min: 1, max: 255)]
        public string $name = '',

        #[Assert\Choice(choices: ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], multiple: true)]
        public array $roles = ['ROLE_USER'],
    ) {
    }
}
