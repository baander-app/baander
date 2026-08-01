<?php

declare(strict_types=1);

namespace App\Party\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

#[OA\Schema(
    schema: 'CreatePartySessionRequest',
    required: ['videoId', 'transcodeJobId'],
    properties: [
        new OA\Property(property: 'videoId', type: 'string', format: 'uuid'),
        new OA\Property(property: 'transcodeJobId', type: 'string', format: 'uuid'),
        new OA\Property(property: 'maxMembers', type: 'integer', example: 10, maximum: 50, minimum: 2),
    ],
)]
final readonly class CreatePartySessionRequest
{
    public function __construct(
        #[NotBlank(message: 'Video ID is required.')]
        public string $videoId = '',
        #[NotBlank(message: 'Transcode job ID is required.')]
        public string $transcodeJobId = '',
        #[Range(min: 2, max: 50)]
        public int $maxMembers = 10,
    )
    {
    }
}
