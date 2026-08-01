<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Choice;

#[OA\Schema(
    schema: 'CreateTranscodeSessionRequest',
    required: ['videoId'],
    properties: [
        new OA\Property(property: 'videoId', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000'),
        new OA\Property(property: 'qualityTier', type: 'string', example: '1080p', enum: ['360p', '480p', '720p', '1080p', '1440p', '4K']),
        new OA\Property(property: 'audioProfile', type: 'string', example: 'streaming_stereo', enum: ['mobile_mono', 'mobile_stereo', 'streaming_stereo', 'streaming_5.1', 'broadcast_stereo', 'broadcast_5.1', 'hifi_stereo', 'opus_stereo']),
        new OA\Property(property: 'priority', type: 'string', example: 'normal', enum: ['critical', 'high', 'normal', 'low', 'bulk']),
    ],
)]
final readonly class CreateTranscodeSessionRequest
{
    public function __construct(
        #[NotBlank(message: 'Video ID is required.')]
        public string $videoId = '',
        #[Choice(choices: ['360p', '480p', '720p', '1080p', '1440p', '4K'])]
        public string $qualityTier = '1080p',
        #[Choice(choices: ['mobile_mono', 'mobile_stereo', 'streaming_stereo', 'streaming_5.1', 'broadcast_stereo', 'broadcast_5.1', 'hifi_stereo', 'opus_stereo'])]
        public string $audioProfile = 'streaming_stereo',
        #[Choice(choices: ['critical', 'high', 'normal', 'low', 'bulk'])]
        public string $priority = 'normal',
    ) {
    }
}
