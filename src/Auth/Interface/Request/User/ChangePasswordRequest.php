<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\User;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

#[OA\Schema(
    type: 'object',
    properties: [
        'currentPassword' => new OA\Schema(
            type: 'string',
            example: '********',
            default: '',
        ),
        'newPassword' => new OA\Schema(
            type: 'string',
            minLength: 8,
            maxLength: 255,
            example: '********',
            default: '',
        ),
    ],
    required: ['currentPassword', 'newPassword'],
    component: 'ChangePasswordRequest',
)]
final readonly class ChangePasswordRequest
{
    public function __construct(
        #[NotBlank(message: 'Current password is required.')]
        public string $currentPassword = '',

        #[NotBlank(message: 'New password is required.')]
        #[Length(min: 8, max: 255, minMessage: 'Password must be at least {{ limit }} characters.')]
        public string $newPassword = '',
    ) {
    }
}
