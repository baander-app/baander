<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints\Choice;

#[OA\Schema(
    type: 'object',
    properties: [
        'audioProfile' => new OA\Schema(
            type: 'string',
            nullable: true,
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
            default: null,
        ),
    ],
    component: 'UpdateTranscodeSessionRequest',
)]
final readonly class UpdateTranscodeSessionRequest
{
    public function __construct(
        #[Choice(choices: ['mobile_mono', 'mobile_stereo', 'streaming_stereo', 'streaming_5.1', 'broadcast_stereo', 'broadcast_5.1', 'hifi_stereo', 'opus_stereo'])]
        public ?string $audioProfile = null,
    ) {
    }
}
