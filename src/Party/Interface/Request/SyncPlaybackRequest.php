<?php

declare(strict_types=1);

namespace App\Party\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

#[OA\Schema(
    schema: 'SyncPlaybackRequest',
    required: ['clientPosition', 'clientLatency'],
    properties: [
        new OA\Property(property: 'clientPosition', type: 'number', format: 'float', example: 42.5),
        new OA\Property(property: 'clientLatency', type: 'number', format: 'float', example: 0.15),
    ],
)]
final readonly class SyncPlaybackRequest
{
    public function __construct(
        #[NotBlank]
        public float $clientPosition = 0.0,
        #[NotBlank]
        #[Range(min: 0, max: 10)]
        public float $clientLatency = 0.0,
    ) {
    }
}
