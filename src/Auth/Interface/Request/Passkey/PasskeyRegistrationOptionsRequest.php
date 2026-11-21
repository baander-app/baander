<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\Passkey;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\NotBlank;

#[OA\Schema(
    type: 'object',
    properties: [
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
    required: ['currentPassword'],
    component: 'PasskeyRegistrationOptionsRequest',
)]
final readonly class PasskeyRegistrationOptionsRequest
{
    public function __construct(
        #[NotBlank(message: 'Current password is required.')]
        public string $currentPassword = '',

        // Optional on purpose: a missing code gets the same answer as a wrong password.
        public ?string $totpCode = null,
    ) {
    }
}
