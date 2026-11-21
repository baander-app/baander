<?php

declare(strict_types=1);

namespace App\Radio\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'stationId' => new OA\Schema(
            type: 'string',
            minLength: 21,
            maxLength: 21,
        ),
        'streamUrl' => new OA\Schema(type: 'string'),
    ],
    required: ['stationId', 'streamUrl'],
    component: 'StartRadioRequest',
)]
final readonly class StartRadioRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(exactly: 21)]
        #[Assert\Regex(pattern: '/^[0-9a-zA-Z_-]{21}$/D')]
        public string $stationId,

        #[Assert\NotBlank]
        #[Assert\Url]
        public string $streamUrl,
    ) {
    }
}
