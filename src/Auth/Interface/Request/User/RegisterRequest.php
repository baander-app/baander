<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\User;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;

#[OA\Schema(
    schema: 'RegisterRequest',
    required: ['name', 'email', 'password'],
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'Alice', maxLength: 255),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'alice@example.com', maxLength: 255),
        new OA\Property(property: 'password', type: 'string', example: '********', maxLength: 255),
    ],
)]
final readonly class RegisterRequest
{
    public function __construct(
        #[NotBlank(message: 'Name is required.')]
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
