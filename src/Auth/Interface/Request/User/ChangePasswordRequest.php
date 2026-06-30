<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\User;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

#[OA\Schema(
    schema: 'ChangePasswordRequest',
    required: ['currentPassword', 'newPassword'],
    properties: [
        new OA\Property(property: 'currentPassword', type: 'string', example: '********'),
        new OA\Property(property: 'newPassword', type: 'string', example: '********', minLength: 8),
    ],
)]
final readonly class ChangePasswordRequest
{
    public function __construct(
        #[NotBlank(message: 'Current password is required.')]
        public string $currentPassword = '',

        #[NotBlank(message: 'New password is required.')]
        #[Length(min: 8, minMessage: 'Password must be at least {{ limit }} characters.')]
        public string $newPassword = '',
    ) {
    }
}
