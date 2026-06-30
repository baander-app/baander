<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Domain\Model\Video;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'VideoResource',
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid', description: 'Video UUID'),
        new OA\Property(property: 'publicId', type: 'string', format: 'uuid', description: 'Public-facing UUID'),
        new OA\Property(property: 'path', type: 'string', description: 'File path'),
        new OA\Property(property: 'duration', type: 'number', nullable: true, description: 'Duration in seconds'),
        new OA\Property(property: 'height', type: 'integer', nullable: true, description: 'Video height in pixels'),
        new OA\Property(property: 'width', type: 'integer', nullable: true, description: 'Video width in pixels'),
        new OA\Property(property: 'videoBitrate', type: 'integer', nullable: true, description: 'Video bitrate in bps'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', description: 'Creation timestamp'),
    ],
)]
final class VideoResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof Video);

        return [
            'uuid' => $source->getId()->toString(),
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
