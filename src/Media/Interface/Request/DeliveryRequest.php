<?php

declare(strict_types=1);

namespace App\Media\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[\Symfony\Component\DependencyInjection\Attribute\Exclude]
#[OA\Schema(
    type: 'object',
    properties: [
        'kind' => new OA\Schema(
            type: 'string',
            enum: ['track', 'video'],
        ),
        'id' => new OA\Schema(type: 'string'),
        'containers' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(type: 'string'),
            minItems: 1,
            maxItems: 32,
        ),
        'videoCodecs' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(type: 'string'),
            maxItems: 32,
            default: [],
        ),
        'audioCodecs' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(type: 'string'),
            maxItems: 32,
            default: [],
        ),
        'maxWidth' => new OA\Schema(
            type: 'integer',
            nullable: true,
            minimum: 0,
            exclusiveMinimum: true,
            default: null,
        ),
        'maxHeight' => new OA\Schema(
            type: 'integer',
            nullable: true,
            minimum: 0,
            exclusiveMinimum: true,
            default: null,
        ),
        'maxAudioChannels' => new OA\Schema(
            type: 'integer',
            nullable: true,
            minimum: 0,
            exclusiveMinimum: true,
            default: null,
        ),
        'audioStreamIndex' => new OA\Schema(
            type: 'integer',
            nullable: true,
            minimum: 0,
            default: null,
        ),
        'subtitleBurnIn' => new OA\Schema(type: 'boolean', default: false),
        'transports' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(type: 'string'),
            default: ['file'],
        ),
        'maxBitDepth' => new OA\Schema(
            type: 'integer',
            minimum: 8,
            maximum: 16,
            default: 8,
        ),
        'supportsHdr' => new OA\Schema(type: 'boolean', default: false),
        'pixelFormats' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(type: 'string'),
            maxItems: 32,
            default: ['yuv420p', 'yuvj420p', 'nv12'],
        ),
        'requestId' => new OA\Schema(
            type: 'string',
            nullable: true,
            default: null,
        ),
        'sourcePlaybackFailed' => new OA\Schema(type: 'boolean', default: false),
        'audioOffsetMs' => new OA\Schema(
            type: 'integer',
            minimum: -10000,
            maximum: 10000,
            default: 0,
        ),
        'qualityTier' => new OA\Schema(
            type: 'string',
            nullable: true,
            enum: ['360p', '480p', '720p', '1080p', '1440p', '4K', null],
            default: null,
        ),
    ],
    required: ['kind', 'id', 'containers'],
    component: 'DeliveryRequest',
)]
final readonly class DeliveryRequest
{
    /**
     * @param list<string> $containers
     * @param list<string> $videoCodecs
     * @param list<string> $audioCodecs
     * @param list<string> $transports
     * @param list<string> $pixelFormats
     */
    public function __construct(
        #[Assert\Choice(choices: ['track', 'video'])]
        public string $kind,
        #[Assert\NotBlank]
        public string $id,
        #[Assert\Count(min: 1, max: 32)]
        public array $containers,
        #[Assert\Count(max: 32)]
        public array $videoCodecs = [],
        #[Assert\Count(max: 32)]
        public array $audioCodecs = [],
        #[Assert\Positive]
        public ?int $maxWidth = null,
        #[Assert\Positive]
        public ?int $maxHeight = null,
        #[Assert\Positive]
        public ?int $maxAudioChannels = null,
        #[Assert\PositiveOrZero]
        public ?int $audioStreamIndex = null,
        public bool $subtitleBurnIn = false,
        public array $transports = ['file'],
        #[Assert\Range(min: 8, max: 16)]
        public int $maxBitDepth = 8,
        public bool $supportsHdr = false,
        #[Assert\Count(max: 32)]
        public array $pixelFormats = ['yuv420p', 'yuvj420p', 'nv12'],
        #[Assert\Uuid]
        public ?string $requestId = null,
        public bool $sourcePlaybackFailed = false,
        #[Assert\Range(min: -10000, max: 10000)]
        public int $audioOffsetMs = 0,
        public ?string $qualityTier = null,
    ) {}
}
