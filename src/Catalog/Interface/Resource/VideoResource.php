<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Domain\Model\Video;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'VideoResource',
    properties: [
        new OA\Property(property: 'publicId', description: 'Public identifier', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'path', description: 'File path', type: 'string'),
        new OA\Property(property: 'duration', description: 'Duration in seconds', type: 'number', nullable: true),
        new OA\Property(property: 'height', description: 'Video height in pixels', type: 'integer', nullable: true),
        new OA\Property(property: 'width', description: 'Video width in pixels', type: 'integer', nullable: true),
        new OA\Property(property: 'videoBitrate', description: 'Video bitrate in bps', type: 'integer', nullable: true),
        new OA\Property(property: 'createdAt', description: 'Creation timestamp', type: 'string', format: 'date-time'),
    ],
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
