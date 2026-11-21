<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\User;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\Email as EmailConstraint;
use Symfony\Component\Validator\Constraints\NotBlank;

#[OA\Schema(
    type: 'object',
    properties: [
        'email' => new OA\Schema(
            type: 'string',
            format: 'email',
            example: 'alice@example.com',
            default: '',
        ),
        'currentPassword' => new OA\Schema(
            type: 'string',
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
    required: ['email', 'currentPassword'],
    component: 'ChangeEmailRequest',
)]
final readonly class ChangeEmailRequest
{
    public function __construct(
        #[NotBlank(message: 'Email is required.')]
        #[EmailConstraint(message: 'Please provide a valid email address.')]
        public string $email = '',

        #[NotBlank(message: 'Current password is required.')]
        public string $currentPassword = '',

        // Optional on purpose: a missing code gets the same answer as a wrong password.
        public ?string $totpCode = null,
    ) {
    }
}
