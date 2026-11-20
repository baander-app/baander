<?php

declare(strict_types=1);

namespace App\Library\Interface\Resource;

use App\Library\Domain\Model\Library;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'LibraryResource',
    properties: [
        new OA\Property(property: 'publicId', description: 'Library public ID', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'name', description: 'Library name', type: 'string'),
        new OA\Property(property: 'slug', description: 'URL-friendly slug', type: 'string'),
        new OA\Property(property: 'path', description: 'Filesystem path', type: 'string'),
        new OA\Property(property: 'type', description: 'Library type', type: 'string', enum: ['music', 'podcast', 'audiobook', 'movie', 'tv_show']),
        new OA\Property(property: 'filesystemType', description: 'Filesystem type', type: 'string', enum: ['local']),
        new OA\Property(property: 'sortOrder', description: 'Sort order', type: 'integer'),
        new OA\Property(property: 'lastScan', description: 'Last scan timestamp', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'scanStatus', description: 'Current scan status', type: 'string', nullable: true),
        new OA\Property(property: 'createdAt', description: 'Creation timestamp', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', description: 'Last update timestamp', type: 'string', format: 'date-time'),
    ],
)]
final class LibraryResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof Library);

        return [
            'publicId' => $source->getPublicId()->toString(),
            'name' => $source->getName(),
            'slug' => $source->getSlug()->toString(),
            'path' => $source->getPath()->toString(),
            'type' => $source->getType()->value,
            'filesystemType' => $source->getFilesystemType()->value,
            'sortOrder' => $source->getSortOrder(),
            'lastScan' => $source->getLastScan()?->format(\DateTimeInterface::ATOM),
            'scanStatus' => $source->getDiscoveryStatus(),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $source->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
