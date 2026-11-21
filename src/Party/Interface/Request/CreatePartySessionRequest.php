<?php

declare(strict_types=1);

namespace App\Party\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Regex;

#[OA\Schema(
    type: 'object',
    properties: [
        'videoId' => new OA\Schema(
            type: 'string',
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
            default: '',
        ),
        'transcodeJobId' => new OA\Schema(
            description: 'Transcode job of the video that the host\'s playback steers',
            type: 'string',
            nullable: true,
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
            default: null,
        ),
        'maxMembers' => new OA\Schema(
            type: 'integer',
            minimum: 2,
            maximum: 50,
            example: 10,
            default: 10,
        ),
    ],
    required: ['videoId'],
    component: 'CreatePartySessionRequest',
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
