<?php

declare(strict_types=1);

namespace App\Session\Interface\Request;

use OpenApi\Spec as OA;
use App\Session\Application\DTO\PlaybackRejectionReason;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'commandId' => new OA\Schema(type: 'string'),
        'attemptId' => new OA\Schema(type: 'string'),
        'revision' => new OA\Schema(type: 'integer', minimum: 0),
        'incarnationId' => new OA\Schema(type: 'string'),
        'epoch' => new OA\Schema(
            type: 'integer',
            minimum: 0,
            exclusiveMinimum: true,
        ),
        'outcome' => new OA\Schema(
            type: 'string',
            enum: ['prepared', 'quiesced', 'started', 'stopped', 'playing', 'paused', 'applied', 'rejected'],
        ),
        'position' => new OA\Schema(
            type: 'number',
            format: 'float',
            nullable: true,
            minimum: 0,
            default: null,
        ),
        'volume' => new OA\Schema(
            type: 'number',
            format: 'float',
            nullable: true,
            minimum: 0,
            maximum: 1,
            default: null,
        ),
        'rejectionReason' => new OA\Schema(
            type: 'string',
            nullable: true,
            enum: [
                'source_color_metadata_unavailable',
                'video_pixel_format_unsupported',
                'video_bit_depth_unsupported',
                'video_hdr_unsupported',
                'subtitle_burn_in_required',
                'video_codec_unsupported',
                'video_dimensions_unsupported',
                'audio_codec_unsupported',
                'audio_channels_unsupported',
                'source_stream_metadata_unavailable',
                'audio_stream_selection_requires_remux',
                'container_unsupported',
                'audio_stream_unavailable',
                'audio_transcoding_disabled',
                'destination_media_unsupported',
                'subtitle_delivery_unsupported',
                'browser_rejected_source',
                'unknown',
                null,
            ],
            default: null,
        ),
    ],
    required: ['commandId', 'attemptId', 'revision', 'incarnationId', 'epoch', 'outcome'],
    component: 'AcknowledgePlaybackRequest',
)]
final readonly class AcknowledgePlaybackRequest
{
    public function __construct(
        #[Assert\Uuid]
        public string $commandId,
        #[Assert\Uuid]
        public string $attemptId,
        #[Assert\PositiveOrZero]
        public int $revision,
        #[Assert\Uuid]
        public string $incarnationId,
        #[Assert\Positive]
        public int $epoch,
        #[Assert\Choice(choices: ['prepared', 'quiesced', 'started', 'stopped', 'playing', 'paused', 'applied', 'rejected'])]
        public string $outcome,
        #[Assert\PositiveOrZero]
        public ?float $position = null,
        #[Assert\Range(min: 0, max: 1)]
        public ?float $volume = null,
        #[Assert\Choice(choices: PlaybackRejectionReason::VALUES)]
        #[Assert\Expression('value == null or this.outcome == "rejected"', message: 'A rejection reason requires a rejected outcome.')]
        public ?string $rejectionReason = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_filter(get_object_vars($this), static fn(mixed $value): bool => $value !== null);
    }
}
