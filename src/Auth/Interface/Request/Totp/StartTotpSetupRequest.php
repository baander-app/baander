<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\Totp;

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
    ],
    required: ['currentPassword'],
    component: 'StartTotpSetupRequest',
)]
final readonly class StartTotpSetupRequest
{
    public function __construct(
        #[NotBlank(message: 'Current password is required.')]
        public string $currentPassword = '',
    ) {
    }
}
