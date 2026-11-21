<?php

declare(strict_types=1);

namespace App\Party\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

#[OA\Schema(
    type: 'object',
    properties: [
        'newHostUserId' => new OA\Schema(
            type: 'string',
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
            default: '',
        ),
    ],
    required: ['newHostUserId'],
    component: 'TransferHostRequest',
)]
final readonly class TransferHostRequest
{
    public function __construct(
        #[NotBlank]
        #[Regex(pattern: '/^[A-Za-z0-9_-]{21}$/D')]
        public string $newHostUserId = '',
    ) {
    }
}
