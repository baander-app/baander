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
        'totpCode' => new OA\Schema(
            description: 'A current TOTP code; needed when TOTP is on',
            type: 'string',
            nullable: true,
            example: '123456',
            default: null,
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

        // Optional on purpose: a missing code gets the same answer as a wrong password.
        public ?string $totpCode = null,
    ) {
    }
}
