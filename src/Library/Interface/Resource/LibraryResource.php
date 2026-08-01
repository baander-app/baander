<?php

declare(strict_types=1);

namespace App\Library\Interface\Resource;

use App\Library\Domain\Model\Library;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'LibraryResource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid', description: 'Library UUID'),
        new OA\Property(property: 'name', type: 'string', description: 'Library name'),
        new OA\Property(property: 'slug', type: 'string', description: 'URL-friendly slug'),
        new OA\Property(property: 'path', type: 'string', description: 'Filesystem path'),
        new OA\Property(property: 'type', type: 'string', enum: ['music', 'podcast', 'audiobook', 'movie', 'tv_show'], description: 'Library type'),
        new OA\Property(property: 'filesystemType', type: 'string', enum: ['local'], description: 'Filesystem type'),
        new OA\Property(property: 'sortOrder', type: 'integer', description: 'Sort order'),
        new OA\Property(property: 'lastScan', type: 'string', format: 'date-time', nullable: true, description: 'Last scan timestamp'),
        new OA\Property(property: 'scanStatus', type: 'string', nullable: true, description: 'Current scan status'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', description: 'Creation timestamp'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time', description: 'Last update timestamp'),
    ],
)]
final class LibraryResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof Library);

        return [
            'id' => $source->getId()->toString(),
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
