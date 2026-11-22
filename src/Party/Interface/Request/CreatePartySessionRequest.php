<?php

declare(strict_types=1);

namespace App\Party\Interface\Request;

use App\Shared\Domain\Model\PublicId;
use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Regex;

#[OA\Schema(
    type: 'object',
    properties: [
        'videoId' => new OA\Schema(
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
            default: '',
        ),
        'transcodeJobId' => new OA\Schema(
            description: 'The host\'s own transcode job of the video, which the host\'s playback steers',
            type: 'string',
            nullable: true,
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
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
        #[Regex(pattern: PublicId::REGEX)]
        public string $videoId = '',
        #[NotBlank(allowNull: true)]
        #[Regex(pattern: PublicId::REGEX)]
        public ?string $transcodeJobId = null,
        #[Range(min: 2, max: 50)]
        public int $maxMembers = 10,
    )
    {
    }
}
