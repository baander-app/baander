<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Request;

use App\Shared\Domain\Model\PublicId;
use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Uuid;

#[OA\Schema(
    type: 'object',
    properties: [
        'videoId' => new OA\Schema(
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
            example: '0123456789abABCD_-xyz',
            default: '',
        ),
        'requestId' => new OA\Schema(
            description: 'Stable playback client session identifier',
            type: 'string',
            format: 'uuid',
            default: '',
        ),
        'qualityTier' => new OA\Schema(
            type: 'string',
            enum: ['360p', '480p', '720p', '1080p', '1440p', '4K'],
            example: '1080p',
            default: '1080p',
        ),
        'audioProfile' => new OA\Schema(
            type: 'string',
            enum: [
                'mobile_mono',
                'mobile_stereo',
                'streaming_stereo',
                'streaming_5.1',
                'broadcast_stereo',
                'broadcast_5.1',
                'hifi_stereo',
                'opus_stereo',
            ],
            example: 'streaming_stereo',
            default: 'streaming_stereo',
        ),
        'audioStreamIndex' => new OA\Schema(
            description: 'Absolute source audio stream index; null selects the source default',
            type: 'integer',
            nullable: true,
            minimum: 0,
            default: null,
        ),
        'audioOffsetMs' => new OA\Schema(
            description: 'Source audio correction in milliseconds; positive plays later',
            type: 'integer',
            minimum: -10000,
            maximum: 10000,
            default: 0,
        ),
        'priority' => new OA\Schema(
            type: 'string',
            enum: ['critical', 'high', 'normal', 'low', 'bulk'],
            example: 'normal',
            default: 'normal',
        ),
    ],
    required: ['videoId', 'requestId'],
    component: 'CreateTranscodeSessionRequest',
)]
final readonly class CreateTranscodeSessionRequest
{
    public function __construct(
        #[NotBlank(message: 'Video ID is required.')]
        #[Length(exactly: PublicId::LENGTH)]
        #[Regex(pattern: PublicId::REGEX)]
        public string $videoId = '',
        #[Choice(choices: ['360p', '480p', '720p', '1080p', '1440p', '4K'])]
        public string $qualityTier = '1080p',
        #[Choice(choices: [
            'mobile_mono',
            'mobile_stereo',
            'streaming_stereo',
            'streaming_5.1',
            'broadcast_stereo',
            'broadcast_5.1',
            'hifi_stereo',
            'opus_stereo',
        ])]
        public string $audioProfile = 'streaming_stereo',
        #[Choice(choices: ['critical', 'high', 'normal', 'low', 'bulk'])]
        public string $priority = 'normal',
        #[NotBlank]
        #[Uuid]
        public string $requestId = '',
        #[PositiveOrZero]
        public ?int $audioStreamIndex = null,
        #[Range(min: -10000, max: 10000)]
        public int $audioOffsetMs = 0,
    ) {
    }
}
