<?php

declare(strict_types=1);

namespace App\Discovery\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\NotBlank;

#[OA\Schema(
    type: 'object',
    properties: [
        'pairingCode' => new OA\Schema(
            type: 'string',
            example: 'BCDF-GHJK',
            default: '',
        ),
        'serverPublicId' => new OA\Schema(
            type: 'string',
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
            default: '',
        ),
    ],
    required: ['pairingCode', 'serverPublicId'],
    component: 'CompletePairingRequest',
)]
final readonly class CompletePairingRequest
{
    public function __construct(
        #[NotBlank(message: 'Pairing code is required.')]
        public string $pairingCode = '',
        #[NotBlank(message: 'Server public ID is required.')]
        public string $serverPublicId = '',
    ) {
    }
}
