<?php

declare(strict_types=1);

namespace App\Party\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Regex;

#[OA\Schema(
    schema: 'CreatePartySessionRequest',
    required: ['videoId'],
    properties: [
        new OA\Property(property: 'videoId', type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$'),
        new OA\Property(property: 'transcodeJobId', type: 'string', minLength: 21, maxLength: 21, pattern: '^[0-9a-zA-Z_-]{21}$', nullable: true, description: 'Transcode job of the video that the host\'s playback steers'),
        new OA\Property(property: 'maxMembers', type: 'integer', example: 10, maximum: 50, minimum: 2),
    ],
)]
final readonly class CreatePartySessionRequest
{
    public function __construct(
        #[NotBlank(message: 'Video ID is required.')]
        #[Regex(pattern: '/^[A-Za-z0-9_-]{21}$/D')]
        public string $videoId = '',
        #[NotBlank(allowNull: true)]
        #[Regex(pattern: '/^[A-Za-z0-9_-]{21}$/D')]
        public ?string $transcodeJobId = null,
        #[Range(min: 2, max: 50)]
        public int $maxMembers = 10,
    )
    {
    }
}
