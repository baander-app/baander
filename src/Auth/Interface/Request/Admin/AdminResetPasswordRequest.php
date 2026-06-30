<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\Admin;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'AdminResetPasswordRequest',
    required: ['password'],
    properties: [
        new OA\Property(property: 'password', type: 'string', example: '********', minLength: 8),
    ],
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
