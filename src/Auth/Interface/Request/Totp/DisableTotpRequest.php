<?php

declare(strict_types=1);

namespace App\Auth\Interface\Request\Totp;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Length;

#[OA\Schema(
    schema: 'DisableTotpRequest',
    required: ['code'],
    properties: [
        new OA\Property(property: 'code', type: 'string', example: '123456', maxLength: 6, minLength: 6),
    ],
)]
final readonly class DisableTotpRequest
{
    public function __construct(
        #[NotBlank(message: 'code is required.')]
        #[Length(exactly: 6)]
        public string $code = '',
    ) {
    }
}
