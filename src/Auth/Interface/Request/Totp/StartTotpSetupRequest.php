<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\Totp;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;

#[OA\Schema(
    schema: 'StartTotpSetupRequest',
    required: ['currentPassword'],
    properties: [
        new OA\Property(property: 'currentPassword', type: 'string', example: '********'),
    ],
)]
final readonly class StartTotpSetupRequest
{
    public function __construct(
        #[NotBlank(message: 'Current password is required.')]
        public string $currentPassword = '',
    ) {
    }
}
