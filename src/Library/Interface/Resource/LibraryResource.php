<?php

declare(strict_types=1);

namespace App\Library\Interface\Resource;

use App\Library\Domain\Model\Library;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'publicId' => new OA\Schema(
            description: 'Library public ID',
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
        'name' => new OA\Schema(description: 'Library name', type: 'string'),
        'slug' => new OA\Schema(description: 'URL-friendly slug', type: 'string'),
        'path' => new OA\Schema(description: 'Filesystem path', type: 'string'),
        'type' => new OA\Schema(
            description: 'Library type',
            type: 'string',
            enum: ['music', 'podcast', 'audiobook', 'movie', 'tv_show'],
        ),
        'filesystemType' => new OA\Schema(
            description: 'Filesystem type',
            type: 'string',
            enum: ['local'],
        ),
        'sortOrder' => new OA\Schema(description: 'Sort order', type: 'integer'),
        'lastScan' => new OA\Schema(
            description: 'Last scan timestamp',
            type: 'string',
            format: 'date-time',
            nullable: true,
        ),
        'scanStatus' => new OA\Schema(
            description: 'Current scan status',
            type: 'string',
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
    required: ['publicId', 'name', 'slug', 'path', 'type', 'filesystemType', 'sortOrder', 'createdAt', 'updatedAt'],
    component: 'LibraryResource',
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
