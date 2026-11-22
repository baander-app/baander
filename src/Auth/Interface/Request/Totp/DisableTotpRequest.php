<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\Totp;

use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'currentPassword' => new OA\Schema(
            type: 'string',
            example: '********',
            default: '',
        ),
        'totpCode' => new OA\Schema(
            description: 'A current TOTP code',
            type: 'string',
            nullable: true,
            example: '123456',
            default: null,
        ),
    ],
    required: ['currentPassword', 'totpCode'],
    component: 'DisableTotpRequest',
)]
final readonly class DisableTotpRequest
{
    /*
     * Neither field is validated on purpose: a missing password or code gets the same answer as
     * a wrong one, so turning TOTP off from a session without the password reveals nothing.
     */
    public function __construct(
        public string $currentPassword = '',
        public ?string $totpCode = null,
    ) {
    }
}
