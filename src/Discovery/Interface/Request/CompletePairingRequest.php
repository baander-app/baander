<?php

declare(strict_types=1);

namespace App\Discovery\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;

#[OA\Schema(
    schema: 'CompletePairingRequest',
    required: ['pairingCode', 'serverPublicId'],
    properties: [
        new OA\Property(property: 'pairingCode', type: 'string', example: 'BCDF-GHJK'),
        new OA\Property(property: 'serverPublicId', type: 'string', format: 'uuid'),
    ],
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
