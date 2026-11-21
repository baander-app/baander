<?php

declare(strict_types=1);

namespace App\Party\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

#[OA\Schema(
    type: 'object',
    properties: [
        'clientPosition' => new OA\Schema(
            type: 'number',
            format: 'float',
            example: 42.5,
            default: 0.0,
        ),
        'clientLatency' => new OA\Schema(
            type: 'number',
            format: 'float',
            minimum: 0,
            maximum: 10,
            example: 0.15,
            default: 0.0,
        ),
    ],
    required: ['clientPosition', 'clientLatency'],
    component: 'SyncPlaybackRequest',
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
