<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Resource;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Resource\AbstractResource;
use App\Transcode\Application\DTO\TranscodeJobDto;
use OpenApi\Spec as OA;

#[OA\Schema(
    properties: [
        'publicId' => new OA\Schema(
            description: 'Public identifier',
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
        'videoId' => new OA\Schema(
            description: 'Video public identifier',
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
        'qualityTier' => new OA\Schema(description: 'Quality tier name', type: 'string'),
        'status' => new OA\Schema(
            description: 'Job status',
            type: 'string',
            enum: ['pending', 'in_progress', 'completed', 'failed', 'cancelled'],
        ),
        'referenceCount' => new OA\Schema(description: 'Number of references', type: 'integer'),
        'totalSegments' => new OA\Schema(description: 'Total segments to transcode', type: 'integer'),
        'completedSegments' => new OA\Schema(description: 'Completed segments', type: 'integer'),
        'progress' => new OA\Schema(description: 'Progress from 0 to 1', type: 'number'),
        'videoCodec' => new OA\Schema(
            description: 'Video codec',
            type: 'string',
            nullable: true,
        ),
        'audioCodec' => new OA\Schema(
            description: 'Audio codec',
            type: 'string',
            nullable: true,
        ),
        'width' => new OA\Schema(
            description: 'Video width in pixels',
            type: 'integer',
            nullable: true,
        ),
        'height' => new OA\Schema(
            description: 'Video height in pixels',
            type: 'integer',
            nullable: true,
        ),
        'createdAt' => new OA\Schema(
            description: 'Creation timestamp',
            type: 'string',
            format: 'date-time',
        ),
        'updatedAt' => new OA\Schema(
            description: 'Last update timestamp',
            type: 'string',
            format: 'date-time',
        ),
    ],
    component: 'TranscodeJobResource',
)]
final class TranscodeJobResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof TranscodeJobDto);

        return array_intersect_key($source->toArray(), array_flip([
            'publicId', 'videoId', 'qualityTier', 'status',
            'referenceCount', 'totalSegments', 'completedSegments', 'progress',
            'videoCodec', 'audioCodec', 'width', 'height',
            'createdAt', 'updatedAt',
        ]));
    }
}
