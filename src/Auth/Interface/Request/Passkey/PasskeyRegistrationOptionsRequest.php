<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\Passkey;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;

#[OA\Schema(
    schema: 'PasskeyRegistrationOptionsRequest',
    required: ['currentPassword'],
    properties: [
        new OA\Property(property: 'currentPassword', type: 'string', example: '********'),
        new OA\Property(property: 'totpCode', description: 'A current TOTP code; needed when TOTP is on', type: 'string', example: '123456', nullable: true),
    ],
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
