<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\Totp;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Length;

#[OA\Schema(
    type: 'object',
    properties: [
        'code' => new OA\Schema(
            type: 'string',
            minLength: 6,
            maxLength: 6,
            example: '123456',
            default: '',
        ),
    ],
    required: ['code'],
    component: 'EnableTotpRequest',
)]
final readonly class EnableTotpRequest
{
    public function __construct(
        #[NotBlank(message: 'code is required.')]
        #[Length(exactly: 6)]
        public string $code = '',
    ) {
    }
}
