<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\Admin;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'password' => new OA\Schema(
            type: 'string',
            minLength: 8,
            maxLength: 255,
            example: '********',
            default: '',
        ),
    ],
    required: ['password'],
    component: 'AdminResetPasswordRequest',
)]
final readonly class AdminResetPasswordRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Password is required.')]
        #[Assert\Length(min: 8, max: 255)]
        public string $password = '',
    ) {
    }
}
