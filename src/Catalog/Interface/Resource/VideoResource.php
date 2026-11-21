<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Domain\Model\Video;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Resource\AbstractResource;
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
        'path' => new OA\Schema(description: 'File path', type: 'string'),
        'duration' => new OA\Schema(
            description: 'Duration in seconds',
            type: 'number',
            nullable: true,
        ),
        'height' => new OA\Schema(
            description: 'Video height in pixels',
            type: 'integer',
            nullable: true,
        ),
        'width' => new OA\Schema(
            description: 'Video width in pixels',
            type: 'integer',
            nullable: true,
        ),
        'videoBitrate' => new OA\Schema(
            description: 'Video bitrate in bps',
            type: 'integer',
            nullable: true,
        ),
        'createdAt' => new OA\Schema(
            description: 'Creation timestamp',
            type: 'string',
            format: 'date-time',
        ),
    ],
    component: 'VideoResource',
)]
final class VideoResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof Video);

        return [
            'publicId' => $source->getPublicId()->toString(),
            'path' => $source->getPath(),
            'duration' => $source->getDuration(),
            'height' => $source->getHeight(),
            'width' => $source->getWidth(),
            'videoBitrate' => $source->getVideoBitrate(),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
